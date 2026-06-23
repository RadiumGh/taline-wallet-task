# Design notes — Taline Wallet & Ledger

The main endpoints — deposit, transfer, history, and the optional withdrawal flow — are the easy part.
What makes this a *money* system is everything that has to stay correct when the network is flaky,
callers retry, several servers touch the same wallet at once, and the gateway calls back more than once.

So I started from a few core properties and let the endpoints follow from them:

- a double-entry ledger as the single source of truth,
- row locking for concurrency,
- idempotency at every layer I could reach,
- explicit state machines for anything with a lifecycle, and
- a transactional outbox plus real observability for side effects.

## What's here

- **Deposit** — `POST /api/deposits` opens a `pending` deposit. No money moves until the gateway calls
back with `confirm` or `fail`. A confirm credits the wallet and debits `gateway_clearing` exactly once,
no matter how many times the callback arrives.
- **Transfer** — `POST /api/transfers`. Synchronous and atomic: debit the sender and credit the
receiver in one locked transaction.
- **History** — `GET /api/wallets/{wallet}/transactions`. Cursor-paginated, with filters.
- **Withdrawal** (optional) — `POST /api/withdrawals` reserves the funds into `withdrawal_clearing`.
Admin `approve`/`settle`/`reject` endpoints drive the state machine, either paying the money out or
releasing the hold.
- Underneath all of them: a single money writer, the layered idempotency and outbox described below, a
reconciliation job for stuck deposits, and a concurrency test suite running on real MySQL.

## Data model

### Wallets and system accounts share one table

Every movement is a balanced set of signed entries that sum to zero. A deposit can't just be "+5000 in
a wallet" — the money has to come from somewhere. So the gateway gets its own clearing account: a
deposit is "credit the user, debit `gateway_clearing`", and a transfer is "debit one wallet, credit
another."

Both are the same shape — move a signed amount between two accounts — so user wallets and system
accounts live in one `wallets` table, separated by `type`. (System accounts have a stable `code` and a
null `user_id`.)

`balance` is a signed BIGINT in minor units. It's just a convenience value I can always rebuild from the
ledger. A CHECK constraint (`type <> 'user' OR balance >= 0`) keeps user wallets from going negative
while still letting a clearing account go negative, which is what double-entry needs. A `version` column
bumps on every write — useful for optimistic locking and as a cheap audit signal.

### The ledger is append-only, and it is the truth

`ledger_entries` is immutable: there's no `updated_at`, and the model refuses `update` and `delete`.
Each entry records:

- the `transaction_group` that ties a movement's legs together,
- the wallet,
- a signed `amount`,
- the `balance_after` captured while the row was locked, and
- a polymorphic reference to what caused it (a `Deposit`, a `Transfer`, or a `Withdrawal`).

Morph types are stored as short aliases — `deposit`, not `App\Models\Deposit` — via
`Relation::enforceMorphMap`, so renaming a class can't corrupt a column full of class names.

Two indexes pull their weight:

- a composite `(wallet_id, created_at, id)` serves the history query and its date filters from a single
index, and
- a unique `(reference_type, reference_id, posting_key, wallet_id)` is the last line of defence against
double-posting — at most one entry per operation *per posting step* per wallet, so a duplicate confirm
physically can't credit twice. (The `posting_key` — `primary` by default, or
`reservation`/`settlement`/`reversal` for withdrawals — is what lets a single withdrawal post to the
same wallet more than once without tripping the guard; see the withdrawal section below.)

### The rest of the tables

- `deposits` — `pending → confirmed | failed` state machine, gateway fields, unique
`(wallet_id, idempotency_key)` on creation, and a `(status, created_at)` index for the reconciliation
sweep.
- `transfers` — the two wallet ids, status, a UUID `reference`, unique `(from_wallet_id, idempotency_key)`.
- `idempotency_keys` — the HTTP layer, Stripe-style: unique `(scope, key)` plus the stored response.
- `gateway_callbacks` — unique `(gateway, event_id)`, so each gateway event is handled exactly once.
- `outbox_events` — durable side-effect log; unique `dedupe_key`, indexed `(status, available_at, id)`.
- `audit_logs` — append-only record of non-money events (transitions, callback receipts) with a request id.

## Design Decisions

### Money is integer minor units, never a float

Amounts are signed BIGINT in minor units. In code they're an immutable `Money` value object that owns
the arithmetic, the currency checks, and the positivity rules. Scales live in `config/wallet.php`
(IRR 0, USD 2, BTC 8), so adding a currency needs no schema change.

Why not a float or `DECIMAL`? Floats lose precision, and `DECIMAL` bakes a single scale into the column.
The one trade-off is BIGINT's ceiling (~9.2×10¹⁸) — far beyond any balance this system will hold.

### Signed amounts and a transaction_group, not debit/credit columns

Compared to separate debit/credit columns, signed amounts plus a `transaction_group` UUID make the
invariants trivial: a wallet's balance is `SUM(amount)`, and a movement is balanced when its legs
`SUM()` to zero. The `balance_after` on each entry lets support replay a wallet line by line and spot
any drift from the materialized balance.

### One writer for all money: `LedgerService::post()`

Exactly one piece of code ever writes to `ledger_entries` or touches `wallets.balance`, and every flow
goes through it. It:

1. runs inside `DB::transaction(attempts: 3)`, so deadlocks retry automatically,
2. locks the wallets with `lockForUpdate()` in ascending id order (consistent lock ordering is what

prevents deadlocks), 3. re-reads the balances *after* locking, 4. writes the legs, updates each balance and `version`, and 5. records the outbox row

all in one transaction, and never calling anything external while holding the locks. The isolation level
barely matters here: REPEATABLE READ on its own won't stop two transactions from both reading a balance
and both spending it. The lock does.

### Idempotency in layers, because everything retries

I assume every request and every callback can arrive more than once, and I guard each layer:

1. **HTTP middleware** on the write POSTs (deposit, transfer, withdrawal request) requires an

`Idempotency-Key`, scoped per user. The first request wins the `(scope, key)` race; a same-key /
same-payload retry replays the stored response; a same-key / different-payload request gets a `409`; and
a 5xx releases the key so a genuine retry isn't stuck. 2. **Unique DB constraints** — deposits `(wallet_id, idempotency_key)`, transfers
`(from_wallet_id, idempotency_key)`, withdrawals `(wallet_id, idempotency_key)`, callbacks
`(gateway, event_id)`, the ledger post guard, and the outbox `dedupe_key`. 3. **State machines** that allow only legal transitions, so a duplicate terminal callback is a no-op. 4. **The ledger's unique `(reference, posting_key, wallet)` guard** underneath everything else.

At-least-once delivery plus idempotent processing gives effectively-once movement — which is the most
anyone can honestly promise.

### Gateway callbacks: authenticated, de-duplicated, state-guarded

Callbacks move money, so they authenticate the caller with an HMAC-SHA256 signature over the raw body.
Each event is recorded once via `(gateway, event_id)`, the deposit row is locked, and the
`pending → confirmed | failed` transition is enforced. A second confirm returns `already_processed`; a
confirm that arrives after a fail is rejected.

### "Timeout means unknown" for stuck deposits

A deposit with no callback stays `pending`. I never auto-fail it, because a missing callback tells us
nothing about what actually happened on the gateway side.

Instead, a scheduled `deposits:reconcile` job (`withoutOverlapping()->onOneServer()`) picks up deposits
that have been pending past a threshold, asks the gateway, and drives the transition through the same
confirm/fail path — producing the same ledger entry, outbox event, and audit log that a webhook would.
It's safe to run repeatedly: the pending filter, a deterministic dedup id, and the ledger guard each
prevent double resolution. The simulated gateway returns `Pending` today; the machinery is real and
tested, and live polling is where a production gateway would slot in.

### Withdrawals: reserve on request, settle or release on review

A withdrawal goes `requested → approved → settled`, or `rejected` from either of the first two states.

The reservation is the key idea. Rather than add a separate "held balance" column, the *request* posts a
real movement: debit the user wallet, credit a `withdrawal_clearing` system account. That means the
user's available balance simply *is* `wallets.balance`, and it drops immediately and exactly once. Every
existing debit path already respects this for free — a transfer can't spend reserved funds because the
balance is already gone — so the reservation needed no changes to the ledger or transfer logic.

From there, *settle* moves `withdrawal_clearing → withdrawal_payout` (the money has left the platform),
and *reject* moves it back to the user. Both post fresh compensating movements rather than editing the
reservation, so the ledger stays append-only.

This is the one flow where a single operation posts to the ledger more than once (reserve, then settle
or reject), all against the same withdrawal row — which clashed with the old anti-double-post guard
`(reference, wallet)`. So entries now carry a `posting_key` (`reservation` / `settlement` / `reversal`),
and the guard became `(reference, posting_key, wallet)`. Deposits and transfers default to `primary` and
are unchanged.

The admin steps reuse the deposit pattern: lock the row, allow only legal transitions, and treat a
repeat of a completed step as `already_processed`. So approve/settle/reject are idempotent under retries,
with the ledger guard as the deeper backstop against a double payout or double refund. Admin authority
itself is *not* modelled — any caller can review.

### A transactional outbox rather than `afterCommit()`

Side effects must not be lost if the process dies after commit, and must not fire if the transaction
rolls back. `afterCommit()` misses the first case: if the process dies between commit and dispatch, the
message is gone.

So an `outbox_events` row commits in the *same* transaction as the money change, and a scheduled
`outbox:relay` publishes the pending rows afterwards, with backoff and jitter. The relay can publish
twice (if it crashes after publishing but before marking the row published), so consumers must be
idempotent — the honest version of "exactly once."

### Cursor pagination for history

History uses keyset pagination via `cursorPaginate()`, ordered by `(created_at desc, id desc)` and
backed by the `(wallet_id, created_at, id)` index, so it doesn't degrade the way offset pagination does
on a large table. The client passes the encoded cursor back, and a tampered cursor is rejected. Filters
compose with it.

### Authorization and rate limiting

Authentication is faked (`X-User-Id`), but authorization is real where it matters. A wallet's history
goes through a `WalletPolicy`, and a wallet you don't own returns `404`, not `403`, so the response
doesn't even confirm the wallet exists. Deposit and transfer don't need a separate policy — each
resolves the caller's own wallet inside its service.

Writes and the history read are rate limited per caller, and callbacks are rate limited per IP, with the
limits in config. I key the limiters off the user header, not the resolved user, so they don't depend on
middleware ordering and the throttle can sit in front of the auth shim.

### Observability

A request-id middleware stamps `X-Request-Id` onto logs, audit rows, and outbox payloads. The immutable
ledger is the money audit trail; `audit_logs` covers everything else (transitions, callback receipts).
Both go through one `OperationRecorder` so the signals can't drift apart. Metrics sit behind a
`MetricsRecorder` interface (a log driver here, Prometheus/StatsD in production) and cover both business
and technical numbers, like idempotency replays and outbox lag. Logs are structured and never carry the
gateway secret or signature.

`**OperationRecorder` — one event, fanned out.** The recorder is built around a single immutable value
object, `OperationEvent` (name, subject, context, an optional actor, and an optional `Measurement`). Each
domain owns a static factory for its own events — `TransferEvent::completed`, `DepositEvent::confirmed`,
`WithdrawalEvent::requested`, and so on. These factories are the *only* place that knows how to turn a
domain model into that shared envelope. A service builds one and hands it off:
`$recorder->record(WithdrawalEvent::requested($withdrawal, $actor))`.

`record()` is the single sink. It fans that one event out to three channels — an `audit_logs` row via
`AuditLogger`, a counter (plus an optional histogram when a `Measurement` is present) via the
`MetricsRecorder` interface, and a structured `Log` line — all carrying the same `request_id`, so audit,
metrics, and logs can never describe the same operation differently.

The indirection buys decoupling in two directions:

- *Domains don't know about observability internals.* Transfer, Deposit, and Withdrawal depend only on
the `OperationEvent` data shape — never on how an audit row is stored or which metrics backend is wired.
The `MetricsRecorder` interface keeps the backend swappable (log → Prometheus/StatsD) without touching a
single domain.
- *The recorder doesn't know about domains.* `record()` has no `match`, no type checks, and no
per-operation branches — it treats every event the same way. That makes it **OCP-closed**: adding a new
operation means adding a new `*Event` factory in that domain, never editing the recorder. And a new
channel (a tracing span, an alert) is added in one place, and every existing event flows through it for
free.

## Left out, on purpose

- **Real authentication** — out of scope; the caller is just the `X-User-Id` header plus an ownership
check. The withdrawal review steps are gated only by that shim, so any caller can approve/settle —
proper admin roles need real auth, which I didn't build.
- **Multi-currency FX** — wallets are per-currency and the schema is ready for more, but there's no
conversion; a cross-currency transfer is rejected, not converted.
- **Dividing money** — `Money` only adds, subtracts, and negates. The first proportional feature (fees,
interest, splits) should add an explicit allocation method with a defined rounding rule, rather than
scattering `intdiv` around and breaking the zero-sum invariant.
- **A real reconciliation gateway** — the mechanism is built and tested; live polling isn't.
- **Fully PK-free public ids** — operations are routed by their UUID `reference`, and ledger-history
lines now carry the operation's `reference` (not its raw `reference_id`), so a client can match a line
to the deposit/withdrawal it holds. The rest is deliberately deferred: wallets still have no public
`reference` (they're passed and returned as raw ids), and operation resources still emit `id` alongside
`reference`. Closing that gap means giving wallets a UUID `reference`, accepting references on input, and
dropping the `id` — an API-contract change worth doing deliberately, not in passing.
- **DB-level ledger immutability** — enforced in the model today; in production I'd add a trigger,
restricted grants, or an append-only role.

## If traffic grew

- **History reads** go to read replicas; the keyset index keeps them cheap.
- **Ledger growth** — partition and archive `ledger_entries` by time; the append-only design makes that
safe.
- **Idempotency, rate limits, hot balances** move to Redis (the idempotency table becomes a lock with a
TTL; hot balances get cached and invalidated on each post).
- **The outbox relay** is the one piece that leans on a single instance. To run several, I'd claim
batches with `SELECT … FOR UPDATE SKIP LOCKED` and a short `publishing` lease, plus a sweep to reclaim
rows from a relay that died mid-publish.
- **Queues** split by workload, so a slow consumer can't starve money-critical work.
- **Hot wallets** get sharded into sub-accounts that sum to the logical balance, or batched posts; the
`version` column already leaves room for an optimistic path.

## What another week buys

1. Real admin authorization for the withdrawal review steps (a role/policy gate, and no self-review),
  once real auth replaces the `X-User-Id` shim.
2. A fully PK-free contract: a wallet UUID `reference`, references accepted on input and returned in
  place of raw wallet ids, and the redundant operation `id` dropped from resources.
3. Real gateway polling in reconciliation, plus an alert on illegal transitions (a fail after a confirm
  is a page-someone event).
4. Metrics and logs emitted from the outbox consumers so they fire exactly once, instead of inside the
  money transaction where a deadlock retry can double-count them.
5. DB-enforced ledger immutability, and a fault-injection test that forces a real deadlock to prove the
  retry recovers.
6. A `Money` allocation API with a documented rounding mode.

