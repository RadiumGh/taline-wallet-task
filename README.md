# Taline Wallet & Ledger

A production-minded slice of a digital wallet: **deposits** (async gateway confirm/fail callbacks),
**transfers** (synchronous, atomic), **cursor-paginated transaction history**, and a **withdrawal
request flow** with admin approve/settle/reject — built on a double-entry ledger that stays correct
under retries, concurrency, and non-exactly-once callbacks.

The design rationale and trade-offs live in **[DESIGN.md](DESIGN.md)**. This file is how to run it.

## Stack

- PHP 8.3+, Laravel 13, Pest 4
- MySQL 8 (real MySQL is required — see [Testing](#testing-real-mysql))
- `database` queue & cache drivers (no Redis needed to run)

## Prerequisites

- PHP 8.3+ with `pdo_mysql` (and `pcntl` to run the concurrency suite)
- Composer
- A reachable MySQL 8 server

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Point `.env` at your MySQL server. **Use `127.0.0.1`, not `localhost`** (a `localhost` socket
connection bypasses TCP auth and fails in this environment):

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=taline_wallet_task
DB_USERNAME=taline
DB_PASSWORD=taline

# Required for the gateway callback HMAC to verify (any shared secret):
WALLET_GATEWAY_SECRET=dev-gateway-secret
```

The configured MySQL user runs the migrations, so it needs schema-level (DDL) privileges, not just
read/write. The simplest grant for local use:

```sql
CREATE DATABASE IF NOT EXISTS taline_wallet_task;
CREATE USER IF NOT EXISTS 'taline'@'127.0.0.1' IDENTIFIED BY 'taline';
-- ALL PRIVILEGES covers CREATE / ALTER / INDEX / DROP / REFERENCES (foreign keys) + read/write:
GRANT ALL PRIVILEGES ON taline_wallet_task.* TO 'taline'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Use those same credentials in `.env` (or just point at your existing privileged user). Then run
migrations and seed system accounts + two demo users (Alice, Bob):

```bash
php artisan migrate --seed
```

The seeder creates the system accounts per currency (`gateway_clearing`, `withdrawal_clearing`,
`withdrawal_payout`) and a wallet for each demo user. Note the seeded wallet ids (system accounts are
created first):

```bash
php artisan tinker --execute="App\Models\Wallet::all(['id','user_id','type','code','currency'])->each(fn(\$w)=>print_r(\$w->toArray()));"
```

## Running

```bash
php artisan serve                 # http://127.0.0.1:8000

# In separate terminals (for async side effects):
php artisan queue:work            # processes outbox-published jobs
php artisan schedule:work         # runs the outbox relay + deposit reconciliation on cron
```

The scheduler (see [routes/console.php](routes/console.php)) runs two jobs
`withoutOverlapping()->onOneServer()`:

- `outbox:relay` (every minute) — publishes pending transactional-outbox events to their consumers.
- `deposits:reconcile` (every five minutes) — resolves deposits stuck `pending` past a threshold
  against the gateway (never auto-fails; "timeout = unknown").

You can also invoke them directly: `php artisan outbox:relay`, `php artisan deposits:reconcile`.

## Testing (real MySQL)

Tests run against a real MySQL schema, not SQLite, so locks, unique/CHECK constraints, and deadlock
behaviour are genuinely exercised. `phpunit.xml` overrides only the database name
(`taline_wallet_task_test`) — host, port, and **credentials are inherited from your `.env`**, so the
same user must also own the test schema. Create it once and grant that user the same DDL privileges
(`RefreshDatabase` migrates the schema and the concurrency suite truncates it, both of which need
CREATE/ALTER/DROP, not just read/write):

```sql
CREATE DATABASE IF NOT EXISTS taline_wallet_task_test;
GRANT ALL PRIVILEGES ON taline_wallet_task_test.* TO 'taline'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Then run the suite:

```bash
php artisan test
```

Test isolation:

- **Unit / Feature** suites use transactional `RefreshDatabase` (fast, rolled back per test).
- The **Concurrency** suite uses non-transactional `DatabaseTruncation`, because real parallel
  clients need committed rows visible across separate connections — a wrapping transaction would
  hide them. Each run starts from a clean schema, so runs never see each other's data. (These tests
  fork with `pcntl` and skip automatically if the extension or a MySQL connection is unavailable.)

## API

Auth is out of scope, so the caller is simulated with an `X-User-Id` header (an "auth shim"
middleware resolves the user). Money crosses the boundary as an **integer in minor units** paired
with a `currency` code — never a float or decimal string (IRR has scale 0, so 5000 = 5000 IRR).

| Header                | Used by                                              | Purpose                                                          |
| --------------------- | ---------------------------------------------------- | ---------------------------------------------------------------- |
| `X-User-Id`           | deposit, transfer, history, withdrawal, admin review | the authenticated user (acts as the reviewer on admin endpoints) |
| `Idempotency-Key`     | `POST` deposit, transfer, withdrawal                 | required; safe-retry key (scoped per user/wallet)                |
| `X-Gateway-Signature` | deposit callbacks                                    | HMAC-SHA256 of the raw body, keyed by `WALLET_GATEWAY_SECRET`    |
| `X-Request-Id`        | all                                                  | correlation id (assigned if absent, echoed back)                 |

Errors are returned as JSON `{ "error": "<code>", "message": "..." }` with an appropriate status
(e.g. `422` insufficient funds / currency mismatch, `409` idempotency or illegal state transition,
`401` bad gateway signature, `404` unknown/unowned wallet).

### Create a deposit (pending)

```bash
curl -sS -X POST http://127.0.0.1:8000/api/deposits \
  -H 'Content-Type: application/json' \
  -H 'X-User-Id: 1' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"wallet_id": 4, "amount": 5000, "currency": "IRR"}'
# -> 201 { "data": { "reference": "...", "status": "pending", ... } }
```

Retrying with the **same** `Idempotency-Key` replays the same response; a different payload under the
same key returns `409`.

### Confirm / fail a deposit (gateway callback)

The gateway authenticates by signing the **raw request body** with the shared secret. Example confirm:

```bash
SECRET=dev-gateway-secret
REF=<deposit-reference-from-create>
BODY='{"event_id":"evt-1"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -sS -X POST "http://127.0.0.1:8000/api/deposits/$REF/callbacks/confirm" \
  -H 'Content-Type: application/json' \
  -H "X-Gateway-Signature: $SIG" \
  -d "$BODY"
# -> 200 { "result": "processed", "data": { "status": "confirmed", ... } }
```

Use `.../callbacks/fail` to mark a deposit failed. Duplicate callbacks (same `event_id`, or a second
confirm) are no-ops returning `"result": "already_processed"`; an illegal transition (e.g. confirm
after fail) returns `409`. A confirmed deposit credits the user wallet and debits `gateway_clearing`
exactly once.

### Transfer (synchronous)

```bash
curl -sS -X POST http://127.0.0.1:8000/api/transfers \
  -H 'Content-Type: application/json' \
  -H 'X-User-Id: 1' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"to_wallet_id": 5, "amount": 1000, "currency": "IRR"}'
# -> 201 { "data": { "reference": "...", "status": "completed", ... } }
```

Insufficient funds, currency mismatch, and self-transfer are rejected with typed `4xx` errors and
move no money.

### Transaction history (cursor-paginated)

```bash
curl -sS "http://127.0.0.1:8000/api/wallets/4/transactions?per_page=10&direction=credit" \
  -H 'X-User-Id: 1'
```

The response is a ledger-line collection (signed `amount`, `balance_after`, `direction`,
`reference_type`) plus Laravel's cursor pagination envelope (`links.next`, `meta.next_cursor`). Pass
that encoded cursor back to page forward:

```bash
curl -sS "http://127.0.0.1:8000/api/wallets/4/transactions?cursor=<next_cursor>" \
  -H 'X-User-Id: 1'
```

Filters: `direction` (`credit`/`debit`), `reference_type` (`deposit`/`transfer`/`withdrawal`),
`date_from`, `date_to`, `per_page` (1–100).

### Withdrawal (request → admin review)

A withdrawal is a two-sided flow. The user **requests** it; an admin then **approves** and
**settles** it (or **rejects** it). The state machine is `requested → approved → settled`, with
`rejected` reachable from either `requested` or `approved`; `settled` and `rejected` are terminal.

Requesting **reserves the funds immediately** — it debits the user wallet and credits the
`withdrawal_clearing` system account, so the balance can't be double-spent while the request is in
review. Settling moves the reserved funds from clearing to `withdrawal_payout`; rejecting reverses
the reservation back to the wallet. Every step is one balanced ledger posting.

```bash
# 1. User requests a withdrawal (reserves funds, status "requested")
curl -sS -X POST http://127.0.0.1:8000/api/withdrawals \
  -H 'Content-Type: application/json' \
  -H 'X-User-Id: 1' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"wallet_id": 4, "amount": 2000, "currency": "IRR"}'
# -> 201 { "data": { "reference": "...", "status": "requested", ... } }
```

The admin endpoints are keyed by the withdrawal **reference** and carry the reviewer in `X-User-Id`:

```bash
REF=<withdrawal-reference-from-request>

# 2. Approve  (requested -> approved)
curl -sS -X POST "http://127.0.0.1:8000/api/admin/withdrawals/$REF/approve" -H 'X-User-Id: 1'

# 3. Settle   (approved -> settled; pays out)
curl -sS -X POST "http://127.0.0.1:8000/api/admin/withdrawals/$REF/settle"  -H 'X-User-Id: 1'

# Or reject  (requested|approved -> rejected; refunds the wallet), with an optional reason
curl -sS -X POST "http://127.0.0.1:8000/api/admin/withdrawals/$REF/reject" \
  -H 'Content-Type: application/json' -H 'X-User-Id: 1' \
  -d '{"reason": "failed AML check"}'
# -> 200 { "result": "processed", "data": { "status": "rejected", "reason": "...", ... } }
```

Review steps are idempotent: repeating a step that already reached its target status is a no-op
returning `"result": "already_processed"`; an illegal transition (e.g. settle before approve)
returns `409`. Insufficient funds at request time and currency mismatch are rejected with typed
`4xx` errors and reserve nothing.
