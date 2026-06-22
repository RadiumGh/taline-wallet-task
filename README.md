# Taline Wallet & Ledger

A production-minded slice of a digital wallet: **deposits** (async gateway confirm/fail callbacks),
**transfers** (synchronous, atomic), and **cursor-paginated transaction history** — built on a
double-entry ledger that stays correct under retries, concurrency, and non-exactly-once callbacks.

The design rationale and trade-offs live in **[DESIGN.md](DESIGN.md)**. This file is how to run it.

## Stack

- PHP 8.5, Laravel 13, Pest 4
- MySQL 8 (real MySQL is required — see [Testing](#testing-real-mysql))
- `database` queue & cache drivers (no Redis needed to run)

## Prerequisites

- PHP 8.5 with `pdo_mysql` (and `pcntl` to run the concurrency suite)
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
DB_USERNAME=your_user
DB_PASSWORD=your_password

# Required for the gateway callback HMAC to verify (any shared secret):
WALLET_GATEWAY_SECRET=dev-gateway-secret
```

Create the schema, run migrations, and seed system accounts + two demo users (Alice, Bob):

```bash
php artisan migrate --seed
```

The seeder creates the `gateway_clearing` system account per currency and a wallet for each demo
user. Note the seeded wallet ids (system accounts are created first):

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
behaviour are genuinely exercised. Create the dedicated test schema once:

```bash
mysql -uYOUR_USER -p -h127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS taline_wallet_task_test"
```

`phpunit.xml` points the suite at `taline_wallet_task_test` over `127.0.0.1`. Run it with:

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

| Header | Used by | Purpose |
|---|---|---|
| `X-User-Id` | deposit, transfer, history | the authenticated user |
| `Idempotency-Key` | `POST` deposit, transfer | required; safe-retry key (scoped per user) |
| `X-Gateway-Signature` | deposit callbacks | HMAC-SHA256 of the raw body, keyed by `WALLET_GATEWAY_SECRET` |
| `X-Request-Id` | all | correlation id (assigned if absent, echoed back) |

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

Filters: `direction` (`credit`/`debit`), `reference_type` (`deposit`/`transfer`),
`date_from`, `date_to`, `per_page` (1–100).

## Project docs

- **[DESIGN.md](DESIGN.md)** — data model, key decisions and trade-offs, what was left out, and how
  this scales.
- `ai-docs/` — the implementation plan, per-step study notes, and the running tech-debt log.
