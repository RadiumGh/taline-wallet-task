# Design notes — Taline Wallet & Ledger

The three endpoints — deposit, transfer, history — aren't the hard part. What makes this a money
system is everything that has to stay true when the network is flaky, callers retry, several servers
hit the same wallet at once, and the gateway calls back more than once. So I built it around a few
properties and let the endpoints fall out of them: a double-entry ledger as the source of truth, row
locking for concurrency, idempotency at every layer I could reach, explicit state machines for anything
with a lifecycle, and a transactional outbox plus real observability for side effects.

## What's here

- **Deposit** — `POST /api/deposits` opens a `pending` deposit; no money moves until the gateway calls
  back `confirm` or `fail`. A confirm credits the wallet and debits `gateway_clearing`, once, however
  many times the callback arrives.
- **Transfer** — `POST /api/transfers`, synchronous and atomic: debit sender, credit receiver, in one
  locked transaction.
- **History** — `GET /api/wallets/{wallet}/transactions`, cursor-paginated with filters.
- Underneath the three: a single money writer, the layered idempotency and outbox detailed below, a
  reconciliation job for stuck deposits, and a concurrency suite on real MySQL.

## Data model

### Wallets and system accounts share one table

Every movement is a balanced set of signed entries summing to zero,
so a deposit can't just be "+5000 in a wallet" — it has to come from somewhere. I gave the gateway a
clearing account: a deposit is "credit the user, debit `gateway_clearing`", a transfer is "debit one
wallet, credit another." Since both are the same shape — move signed amounts between two accounts — user
wallets and system accounts share one `wallets` table, told apart by `type` (system accounts carry a
stable `code` and a null `user_id`). `balance` is a signed BIGINT in minor units, but a materialized
convenience I can rebuild from the ledger; a CHECK (`type <> 'user' OR balance >= 0`) keeps user wallets
non-negative while letting a clearing account go negative, as double-entry wants. A `version` bumps on
every write, for an optimistic-lock option and a cheap audit signal.

### The ledger is append-only, and it is the truth

`ledger_entries` is immutable — no `updated_at`, and the model refuses `update`/`delete`. Each entry
records the `transaction_group` tying a movement's legs together, the wallet, a signed `amount`, the
`balance_after` captured under the lock, and a polymorphic reference to what caused it (a `Deposit` or
`Transfer`). Morph types are stored as short aliases — `deposit`, not `App\Models\Deposit` — via
`Relation::enforceMorphMap`, so renaming a class can't corrupt a column full of class names.

Two indexes earn their keep: a composite `(wallet_id, created_at, id)` serves the history query and its
date filters from one index, and a unique `(reference_type, reference_id, wallet_id)` is the last line
of defence against double-posting — at most one entry per operation per wallet, so a duplicate confirm
physically can't credit twice.

### The rest of the tables

- `deposits` — `pending → confirmed | failed` state machine, gateway fields, unique
  `(wallet_id, idempotency_key)` on creation, `(status, created_at)` index for the reconciliation sweep.
- `transfers` — the two wallet ids, status, UUID `reference`, unique `(from_wallet_id, idempotency_key)`.
- `idempotency_keys` — the HTTP layer, Stripe-style: unique `(scope, key)` plus the stored response.
- `gateway_callbacks` — unique `(gateway, event_id)`, so a gateway event is handled exactly once.
- `outbox_events` — durable side-effect log; unique `dedupe_key`, indexed `(status, available_at, id)`.
- `audit_logs` — append-only record of non-money events (transitions, callback receipts) with request id.

## Design Decisions

### Money is integer minor units, never a float

Amounts are signed BIGINT in minor units; in code they're an immutable `Money` value object owning the
arithmetic, currency checks, and positivity. Scales live in `config/wallet.php` (IRR 0, USD 2, BTC 8),
so a new currency needs no schema change. Floats lose precision; `DECIMAL` bakes one scale into a column.
The trade-off is BIGINT's ~9.2×10¹⁸ ceiling — far past any balance here.

### Signed amounts and a transaction_group, not debit/credit columns

Against a debit/credit-column layout, signed amounts plus a `transaction_group` UUID
make the invariants trivial: a wallet's balance is `SUM(amount)`, and a movement balances
when its legs `SUM()` to zero. `balance_after` on each entry lets support replay a
wallet line by line and spot drift from the materialized balance.

### One writer for all money: `LedgerService::post()`

Exactly one piece of code writes `ledger_entries` or touches `wallets.balance`; every flow goes through
it. It runs in `DB::transaction(attempts: 3)` so deadlocks retry, locks the wallets with
`lockForUpdate()` in ascending id order (consistent ordering is what prevents deadlocks), re-reads
balances _after_ locking, writes the legs, updates each balance and `version`, and records the outbox
row — all in one transaction, never calling anything external while holding locks. The isolation level
barely matters here: REPEATABLE READ alone won't stop two transactions both reading a balance and both
spending it. Safety is the explicit primary-key locks (so InnoDB takes precise row locks), the unique
constraints, and idempotency.

### Idempotency in layers, because everything retries

I assume every request and callback can arrive more than once, and guard each layer:

1. HTTP middleware on the two POSTs requires an `Idempotency-Key` scoped per user: the first request
   wins the `(scope, key)` race, a same-key/same-payload retry replays the stored response, a same-key/
   different-payload one is `409`, and a 5xx releases the key so a real retry isn't wedged.
2. Unique DB constraints — deposits `(wallet_id, idempotency_key)`, transfers
   `(from_wallet_id, idempotency_key)`, callbacks `(gateway, event_id)`, the ledger post guard, the
   outbox `dedupe_key`.
3. State machines that permit only legal transitions, so a duplicate terminal callback is a no-op.
4. The ledger's unique `(reference, wallet)` guard under all of it.

At-least-once delivery plus idempotent processing gives effectively-once movement — the most you can
honestly promise.

### Gateway callbacks: authenticated, de-duplicated, state-guarded

They move money, so they authenticate the caller with an HMAC-SHA256 signature over the raw body. Each
event is recorded once via `(gateway, event_id)`, the deposit row is locked, and the
`pending → confirmed | failed` transition is enforced. A second confirm returns `already_processed`; a
confirm after a fail is rejected.

### "Timeout means unknown" for stuck deposits

A deposit with no callback stays `pending`; I never auto-fail it, since a missing callback says nothing
about what happened gateway-side. A scheduled `deposits:reconcile` job
(`withoutOverlapping()->onOneServer()`) takes deposits pending past a threshold, asks the gateway, and
drives the transition through the same confirm/fail path — producing the same ledger entry, outbox
event, and audit a webhook would. Safe to repeat: the pending filter, a deterministic dedup id, and the
ledger guard each stop double resolution. The simulated gateway returns `Pending` today — the machinery
is real and tested; live polling is where a production gateway slots in.

### A transactional outbox rather than `afterCommit()`

Side effects mustn't be lost if the process dies after commit, nor fire if it rolls back. `afterCommit()`
misses the first case — die between commit and dispatch and the message is gone. So an `outbox_events`
row commits in the same transaction as the money change, and a scheduled `outbox:relay` publishes
pending rows afterwards with backoff and jitter. The relay can publish twice (crash after publishing,
before marking published), so consumers are idempotent — the honest "exactly once."

### Cursor pagination for history

Keyset pagination via `cursorPaginate()`, ordered by `(created_at desc, id desc)` and backed by the
`(wallet_id, created_at, id)` index, so it doesn't degrade like offset pagination on a large table. The
client passes the encoded cursor back; a tampered one is rejected. Filters compose with it.

### Authorization and rate limiting

Authentication is faked (`X-User-Id`), but authorization is real where it counts: a wallet's history
goes through a `WalletPolicy`, and I answer a wallet you don't own with `404`, not `403`, so the
response doesn't confirm it exists. Deposit and transfer need no separate policy — each resolves the
caller's own wallet in its service. Writes and the history read are rate limited per caller, callbacks
per IP, limits in config; I key the limiters off the user header, not the resolved user, so they don't
depend on middleware ordering and the throttle can sit in front of the auth shim.

### Observability

A request-id middleware stamps `X-Request-Id` onto logs, audit rows, and outbox payloads. The immutable
ledger is the money audit; `audit_logs` covers the rest (transitions, callback receipts), both through
one `OperationRecorder` so the signals can't drift. Metrics sit behind a `MetricsRecorder` interface (a
log driver here, Prometheus/StatsD in production) covering business and technical numbers like
idempotency replays and outbox lag. Logs are structured and never carry the gateway secret or signature.

## Left out, on purpose

- **Real authentication** — out of scope; the caller is the `X-User-Id` header plus an ownership check.
- **Withdrawals** — I designed the flow (request → approve → settle) but didn't build it; I'd rather
  ship the core solid than half a fourth feature.
- **Multi-currency FX** — wallets are per-currency and the schema is ready for more, but there's no
  conversion; a cross-currency transfer is rejected, not converted.
- **Dividing money** — `Money` only adds, subtracts, negates. The first proportional feature (fees,
  interest, splits) should add an explicit allocation method with a defined rounding rule rather than
  scattering `intdiv` and breaking the zero-sum invariant.
- **A real reconciliation gateway** — the mechanism is built and tested; the live polling isn't.
- **Cleaner public ids** — resources still expose raw auto-increment ids beside the UUID `reference`;
  I'd rather expose only references and take them on input so primary keys never leak.
- **DB-level ledger immutability** — enforced in the model today; in production I'd add a trigger,
  restricted grants, or an append-only role.

## If traffic grew

- **History reads** go to read replicas; the keyset index keeps them cheap.
- **Ledger growth** — partition and archive `ledger_entries` by time; the append-only design makes that
  safe.
- **Idempotency, rate limits, hot balances** move to Redis (the idempotency table becomes a lock with a
  TTL; hot balances get cached and invalidated on post).
- **The outbox relay** is the one piece leaning on a single instance. To run several I'd claim batches
  with `SELECT … FOR UPDATE SKIP LOCKED` and a short `publishing` lease, plus a sweep to reclaim rows
  from a relay that died mid-publish.
- **Queues** split by workload so a slow consumer can't starve money-critical work.
- **Hot wallets** get sharded into sub-accounts summing to the logical balance, or batched posts; the
  `version` column already leaves room for an optimistic path.

## What another week buys

1. The withdrawal flow, with fund reservation and an admin approve/settle state machine.
2. References-only API resources, so primary keys stop leaking.
3. Real gateway polling in reconciliation, plus an alert on illegal transitions (a fail after a confirm
   is a page-someone event).
4. Metrics and logs emitted from outbox consumers so they fire exactly once, instead of inside the money
   transaction where a deadlock retry can double-count them.
5. DB-enforced ledger immutability, and a fault-injection test that forces a real deadlock to prove the
   retry recovers.
6. A `Money` allocation API with a documented rounding mode.
