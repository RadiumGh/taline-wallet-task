# DESIGN — Taline Wallet & Ledger

This document explains **what was built and why**. The hard part of this task is not the three
endpoints — it is keeping real money correct under **retries, concurrency, and non-exactly-once
gateway callbacks**. The spine of the solution is therefore:

> double-entry ledger (truth) · row locking (concurrency) · idempotency at every layer (duplicates)
> · state machines (legal transitions) · transactional outbox + observability (reliable side
> effects, audit/trace/metrics).

The three endpoints are the surface; those properties are the substance.

## What is implemented

- **Deposit** — `POST /api/deposits` creates a `pending` deposit (no money moves yet); the gateway
  later calls back `confirm` or `fail`. Confirm credits the wallet and debits a `gateway_clearing`
  system account, exactly once.
- **Transfer** — `POST /api/transfers`, synchronous and atomic, debits sender / credits receiver in
  one locked transaction.
- **Transaction history** — `GET /api/wallets/{wallet}/transactions`, cursor-paginated with filters.
- **Cross-cutting** — single ledger writer, layered idempotency, a real transactional outbox with a
  scheduled relay and idempotent consumers, audit/metrics/structured logs, a deposit reconciliation
  job, and a concurrency test suite on real MySQL.

---

## Data model and why

### One unified `wallets` (ledger-account) table

True double-entry means every movement is a balanced set of signed entries that sum to zero, and a
deposit is not money from nowhere — it moves *from a gateway clearing account into the user's
wallet*. Both sides need an account, so **user wallets and system counterparties live in one
table** (`type = user | system`, `code` for system accounts like `gateway_clearing`,
`user_id` null for system). This keeps the double-entry shape uniform: a deposit and a transfer are
the same operation (move signed amounts between two `wallets` rows).

- `balance` is a **materialized** BIGINT (minor units), an optimization — the ledger is the truth.
- A **CHECK** constraint `type <> 'user' OR balance >= 0` lets system/clearing accounts go negative
  by design while a user wallet can *never* go negative, enforced at the DB (not just in code).
- `version` is bumped on every write (audit / optimistic-lock option).

### `ledger_entries` — append-only source of truth

Immutable (no `updated_at`; the model blocks `updating`/`deleting`). Each row:
`transaction_group` (UUID linking the balanced legs), `wallet_id`, signed `amount`, `balance_after`
(running balance captured under the row lock), and a **morph reference** (`reference_type` +
`reference_id` → `Deposit`/`Transfer`) as the brief requires. Morph types are stored as stable
aliases via `Relation::enforceMorphMap` (e.g. `deposit`, not `App\Models\Deposit`), so renaming or
moving a class can never corrupt the polymorphic column.

Two indexes carry their weight:
- Composite `(wallet_id, created_at, id)` — serves the history query and its date-range filters from
  one index (equality → range → tiebreaker), instead of several narrow indexes.
- Unique **`(reference_type, reference_id, wallet_id)`** — the strongest anti-double-post door: a
  given source operation can post at most one entry per wallet. Even if every other guard were
  bypassed, a duplicate confirm physically cannot double-credit.

### Operation & control tables

- `deposits` — `status` state machine (`pending → confirmed | failed`), `gateway`/`gateway_reference`,
  unique `(wallet_id, idempotency_key)` for creation, `(status, created_at)` index for reconciliation.
- `transfers` — `from/to_wallet_id`, `status` (`completed`), `reference` UUID.
- `idempotency_keys` — HTTP-layer (Stripe-style): `(scope, key)` unique, stored response for replay.
- `gateway_callbacks` — `(gateway, event_id)` unique: a given gateway event is processed once.
- `outbox_events` — durable side-effect log with `dedupe_key` unique and a `(status, available_at, id)`
  relay-claim index.
- `audit_logs` — append-only non-money events (status transitions, callback receipts) with
  `request_id`, actor/subject morphs, and JSON context.

---

## Key decisions and trade-offs

### Money: BIGINT minor units + a `Money` value object (not floats, not DECIMAL)

All amounts are signed BIGINT in the currency's minor units; in code they are an immutable `Money`
`(int amount, Currency currency)` with arithmetic, currency-match enforcement, and positivity in one
place. Currencies and their `scale` are config-driven (`config/wallet.php`: IRR scale 0, USD 2,
BTC 8) so multi-currency needs no schema change.

- **vs floats** — floats lose precision; forbidden for money.
- **vs `DECIMAL(x,y)`** — integer minor units are exact, fast, index/compare-friendly, and don't
  bake one scale into a column. Trade-off: BIGINT's signed ceiling (~9.2×10¹⁸) is far beyond any
  realistic balance; a currency needing arbitrary precision would revisit with `DECIMAL`.
- The API only ever exchanges integer minor units + a currency code — no float crosses the boundary.

### Signed `amount` + `transaction_group` (not debit/credit columns or a journal-parent table)

With signed amounts, `balance = SUM(amount)` per wallet is trivially correct and the per-movement
zero-sum invariant is a single `SUM() = 0` check. A debit/credit-column layout or a separate
`ledger_transactions` parent + lines table are both valid; signed minor units + a `transaction_group`
UUID were chosen for the simplest reconciliation story. `balance_after` is stored so finance/support
can replay any wallet line-by-line and detect drift between the materialized balance and the ledger.

### One money writer: `LedgerService::post()`

The only code that writes `ledger_entries` or mutates `wallets.balance`. Every flow (deposit confirm,
transfer) goes through it. It runs in `DB::transaction(attempts: 3)` (deadlock retry), locks the
involved wallets with `lockForUpdate()` **in ascending id order** (global lock ordering prevents
deadlocks), re-checks balances **after** locking, appends balanced legs, computes `balance_after`,
updates balances/`version`, and writes the outbox row — all atomically. **No external/HTTP/gateway
calls happen inside the transaction.**

### Locks + constraints over isolation level

MySQL's default REPEATABLE READ does not by itself make financial writes safe. Correctness comes from
explicit `lockForUpdate()` (locking reads by PK, so InnoDB takes precise row locks), unique
constraints, and idempotency — not from raising the isolation level.

### Idempotency in layers (assume every request/callback arrives more than once)

1. **HTTP middleware** on `POST` deposit/transfer — requires `Idempotency-Key`, scoped per user;
   first request wins the `(scope, key)` race, the stored response is replayed for same key + same
   payload, a different payload under the same key is `409`. Transient 5xx releases the key so a
   genuine retry can proceed.
2. **DB unique constraints** — `deposits (wallet_id, idempotency_key)`,
   `transfers (from_wallet_id, idempotency_key)`, `gateway_callbacks (gateway, event_id)`,
   `ledger_entries (reference, wallet)`, `outbox_events (dedupe_key)`.
3. **State machines** — `canTransitionTo()` guards; duplicate terminal callbacks are no-ops.
4. **Ledger identity** — the unique `(reference, wallet)` post guard is the final backstop.

At-least-once delivery + idempotent processing = **effectively-once** money movement.

### Gateway callbacks: authenticated + de-duplicated + state-guarded

Callback endpoints move money, so they authenticate the caller via an **HMAC-SHA256 signature** over
the raw body (shared secret in config). Each event is recorded once via `gateway_callbacks
(gateway, event_id)`; the deposit row is locked and the `pending → confirmed|failed` transition is
enforced. A second confirm returns `already_processed`; a confirm after fail is rejected.

### Reconciliation: "timeout = unknown"

A deposit with no callback stays `pending` forever rather than being auto-failed. The
`deposits:reconcile` job (scheduled `withoutOverlapping()->onOneServer()`) selects deposits stuck
past a threshold, asks the gateway `fetchStatus()`, and drives the legal transition idempotently —
reusing the same confirm/fail path (so a reconciled confirm posts the same ledger/outbox/audit as a
webhook). It is safe to run repeatedly: the pending filter, the deterministic dedup event id, and the
ledger guard each independently prevent double-resolution.

### Transactional outbox over `afterCommit()`

Side effects (notifications, metric pushes) must not be lost if the process dies between commit and
dispatch, nor fire if the transaction rolls back. So the business write and an `outbox_events` row
commit **atomically**; a scheduled `outbox:relay` then publishes pending rows with exponential
backoff + jitter on failure. The relay can publish twice (crash after publish, before marking
`published`), so consumers are **idempotent** — the honest effectively-once model. Plain
`afterCommit()` would still lose the message on a crash; the durable outbox row does not.

### Cursor pagination

History uses Laravel `cursorPaginate()` ordered by `(created_at desc, id desc)`, backed by the
`(wallet_id, created_at, id)` index — keyset pagination that doesn't degrade at scale. The response
carries an encoded cursor the client passes back; invalid cursors are rejected. Filters (`direction`,
`reference_type`, `date_from`/`date_to`) compose with the cursor.

### Observability

- **Trace** — request-id middleware assigns/propagates `X-Request-Id` into logs, `audit_logs`, and
  outbox payloads.
- **Audit** — immutable `ledger_entries` (money) + append-only `audit_logs` (transitions) = full
  "what happened and why," routed through one `OperationRecorder` so the three signals can't drift.
- **Metrics** — a `MetricsRecorder` interface (`increment`/`gauge`/`histogram`); a log driver here,
  Prometheus/StatsD in production. Business counters/volumes + technical signals (idempotency
  replays/conflicts, outbox lag) are emitted.
- **Logs** — structured, with request id and no secrets/PII (the gateway signature/secret never
  enters a log context).

---

## Deliberately left out (and why)

- **Real authentication** — out of scope per the brief; simulated with an `X-User-Id` auth-shim
  middleware and per-wallet ownership checks.
- **Withdrawal flow** (`request → approve → settle`) — designed in the plan, built last and only if
  core quality held; currently not implemented.
- **Multi-currency FX** — wallets are per-currency and the schema is multi-currency-ready, but there
  is no conversion/rate logic. Cross-currency transfers are rejected, not converted.
- **Money division/allocation** — `Money` is closed under add/subtract/negate only; there is no
  multiply/divide/allocate, so no rounding policy is exercised. The first proportional feature (fees,
  interest, splits) must add an explicit largest-remainder allocation API.
- **Deep gateway reconciliation** — the *mechanism* (query stuck deposits → ask gateway → drive
  transition) is built and tested, but the production `SimulatedPaymentGateway::fetchStatus()` returns
  `Pending` (it has no real status endpoint); a real implementation would poll the provider's API.
- **Public-id hygiene** — API resources currently expose raw sequential ids alongside the UUID
  `reference`. A polished contract would expose only references and accept wallet references on input
  (TD-003).
- **DB-level ledger immutability** — enforced in the Eloquent model today; production would add a DB
  trigger / restricted grants / append-only role.

The running list with risk and suggested fixes is in `ai-docs/todos/tech-debts.md`.

---

## How it copes with far more traffic

- **History reads** — route to **read replicas**; the keyset `(wallet_id, created_at, id)` index
  already avoids offset-scan degradation.
- **Ledger growth** — **partition/archive** `ledger_entries` by time; keep hot recent data, move cold
  data to cheaper storage; the immutable design makes archival safe.
- **Idempotency / rate-limits / hot-balance cache** — move to **Redis** (the `idempotency_keys` table
  becomes a Redis lock with TTL; hot wallet balances can be cached and invalidated on post).
- **Outbox relay** — today correctness rests on a single relay (`withoutOverlapping()->onOneServer()`).
  To scale horizontally, claim batches with `SELECT … FOR UPDATE SKIP LOCKED` + a `publishing` lease
  state and a stuck-row reclaim sweep (TD-007), then run many relay workers.
- **Queue isolation** — separate queues/workers per workload (callbacks vs notifications vs metrics)
  so a slow consumer can't starve money-critical work.
- **Wallet hot-row contention** — for a few extremely hot accounts, shard into sub-accounts that sum
  to the logical balance, or batch posts; the `version` column already supports an optimistic path.

## What another week would add

1. **Withdrawal flow** with fund reservation and an admin approve/settle state machine.
2. **Tighten API resources** to references-only (drop the raw sequential ids — TD-003).
3. **Real gateway polling** in reconciliation, plus alerting on illegal transitions (fail-after-confirm).
4. **Exactly-once metrics/logs** by emitting them from outbox consumers (after commit) instead of
   inside the money transaction, removing the deadlock-retry over-count (TD-008).
5. **DB-enforced ledger immutability** and a deadlock fault-injection test that proves the
   `attempts: 3` retry path recovers.
6. **`Money` allocation API** with a documented rounding mode so proportional math stays zero-sum.
