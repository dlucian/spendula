# Spendula — Specification (v1)

## 1. Overview

### 1.1 What Spendula is

Spendula is a self-hosted single-user personal finance pipeline. It pulls transactions from European bank accounts via Enable Banking's PSD2 API, presents them for human review, and pushes approved transactions into YNAB, which is the system of record for budgeting, categorization, and reporting.

Spendula is not a YNAB alternative. It is a deliberate, narrow bridge that solves the one thing YNAB does poorly in a European, multi-bank, multi-currency household: **ingestion**.

### 1.2 What Spendula is not

- Not a budgeting tool. YNAB budgets.
- Not a categorization engine. YNAB categorizes (LLM assistance is a v2 concern).
- Not a reporting tool. YNAB reports.
- Not a full multi-currency accounting system. In v1, foreign-currency accounts are treated as YNAB tracking accounts only (see §5 for rationale and mechanics).
- Not multi-tenant. One deployment serves one household; v1 supports exactly one human user.

### 1.3 Deployment context

Spendula runs on the operator's home server, accessible over Tailscale at `https://spendula.example.com`. It is not exposed to the public internet.

**Deployment model**: three-container Docker Compose stack (`app`, `web`, `db`) fronted by the host's existing Caddy instance. Only the `web` container publishes a port (`127.0.0.1:8765`); `app` and `db` stay on the compose internal network. The host's Caddy reverse-proxies `spendula.example.com` → `localhost:8765`. Caddy's Tailscale integration provides automatic TLS. The Caddy configuration lives on the host, not in this repository; deployment docs ship a template snippet the operator adds to their existing Caddyfile.

**Local development** is bare metal on macOS (Homebrew Postgres 18, system PHP 8.4). No Docker locally. This deliberate asymmetry keeps dev fast while keeping prod hermetic.

External network calls:

1. Outbound to `api.enablebanking.com` (PSD2 provider)
2. Outbound to `api.ynab.com` (destination of record)
3. Outbound to a chosen exchange rate provider (for tracking-account balance snapshots)

Inbound traffic is tailnet-only. User browsers (laptop, phone) must be on the tailnet when approving transactions or completing bank re-auth flows.

### 1.4 Users

Exactly one user in v1: the operator. The operator's spouse uses YNAB's iOS app directly for budgeting and cash entry and does not interact with Spendula at all. Multi-user access is explicitly deferred.

### 1.5 Weekly ritual

1. Once per week, the operator SSHes to the home server or opens the review CLI locally.
2. Runs a sync, which fetches new transactions from all linked banks.
3. Reviews fetched transactions one by one: Approve, Skip, Tag-as-transfer, or see Details.
4. Runs push, which sends approved transactions to YNAB.
5. Switches to YNAB to categorize what just arrived.
6. Every ~90 days, runs the re-auth flow for each bank whose consent is near expiry.

Wall-time target for a normal weekly session: under 5 minutes.

---

## 2. Core principles

These are the load-bearing decisions. Everything downstream defers to them.

1. **YNAB is the system of record for budgeting.** When in doubt, push responsibility to YNAB.
2. **Spendula is a pipe, not a destination.** Features that could live in YNAB do live in YNAB.
3. **Operator-in-the-loop for every state change.** No transaction moves from `fetched` to `approved` without a human click (v1). No automation is added that can't be disabled.
4. **Raw data is preserved forever.** The full Enable Banking transaction JSON is stored per-row. If a bug requires re-extraction, we have the source.
5. **Deletions are loud and rare.** Spendula records everything and forgets nothing without an explicit command.
6. **Fail loud, never silently.** Errors abort their own flow; they never corrupt shared state.
7. **Currency-of-truth for each account is its native currency.** Conversions are bookkeeping, not primary data.
8. **Idempotency everywhere.** Sync can run repeatedly with no side effects beyond new-transaction discovery. Push retries never double-write.
9. **Build for five banks and one household.** Don't over-generalize.

---

## 3. Architecture

### 3.1 Tech stack

- **Backend**: Laravel 13 on PHP 8.4
- **Database**: PostgreSQL 18
- **Queue / Scheduler**: None in v1. Artisan commands are invoked manually.
- **HTTP client**: Laravel's `Http` facade (Guzzle)
- **JWT**: `firebase/php-jwt`
- **Frontend**: Blade for the OAuth callback landing page only. No JavaScript framework, no build pipeline. Review is CLI-only.
- **Testing**: PHPUnit
- **Static analysis**: PHPStan level 8
- **Formatting**: Laravel Pint, default preset
- **Prod web server**: nginx:alpine in a container, FastCGI to php-fpm. No web server in local dev.

**Local development** runs bare metal on the operator's Mac (Homebrew PostgreSQL 18, system PHP 8.4). Artisan commands are invoked directly; the one HTTP route (OAuth callback) runs via `php artisan serve` when needed. No Docker, no containers, no reverse proxy.

**Production** runs a three-container Docker Compose stack (see §13). Same PHP and Postgres versions as local, pinned at the container level.

### 3.2 Process model

Spendula exposes one HTTP route (the OAuth callback) and a set of artisan commands. Each command below is tagged with its phase-1 state: **real** = fully implemented in phase 1; **stub** = exists as an artisan command but prints "not yet implemented" until its feature lands.

- `spendula:banks:sync` — upsert fixture banks (e.g. `mock`) from `config/spendula-banks.php`; never touches operator-added rows — **real in phase 1**
- `spendula:banks:add` — insert an operator bank into the `banks` table directly. Operator banks never appear in source. — **real in phase 2**
- `spendula:auth:start {bank_slug}` — generate a consent URL for a bank — **real in phase 1**
- `spendula:accounts:map` — interactive mapping of bank accounts to YNAB accounts — **real in phase 2**
- `spendula:sync [--bank=slug]` — fetch new transactions — **real in phase 1** (on-budget flow only; tracking-account path is phase-2)
- `spendula:review` — interactive CLI queue for Approve/Skip/Transfer — **real in phase 1**
- `spendula:push` — push approved transactions to YNAB — **real in phase 1**
- `spendula:status` — dashboard: consent expiry, queued transactions, last sync/push times — **real in phase 4**
- `spendula:convert-pending` — retry failed currency conversions for tracking-account transactions — **stub in phase 1** (no multi-currency in phase 1; deferred follow-up tracked in [dlucian/spendula#23](https://github.com/dlucian/spendula/issues/23))
- `spendula:tracking:snapshot [--account=id]` — compute and push tracking-account balance snapshots — **real in phase 3**

All stubs exist from phase 1 onward so the command surface is stable; callers and docs don't need to change when stubs become real.

Every real command acquires an **advisory lock** (`pg_try_advisory_lock` with a command-specific key) before doing work. Concurrent invocations exit immediately with a message rather than racing. (Carve-outs: `spendula:accounts:map` is idempotent UPDATE-LAST-WINS and does not require a lock; `spendula:status` is read-only and takes no lock.)

### 3.3 Data flow (happy path, on-budget account)

```
Enable Banking ──(sync)──> Spendula DB ──(review)──> Spendula DB ──(push)──> YNAB
                          status=fetched          status=approved          status=pushed
                                                  status=skipped           (terminal)
                                                  status=transfer          (terminal)
                                                  (all are approval
                                                  outcomes from review)
```

### 3.4 Data flow (tracking account, foreign-currency)

```
Enable Banking ──(sync)──> Spendula DB (stores transactions for audit,
                                       status=tracking, never pushes
                                       individually)
                          ↓
Periodic or on-demand:
                          (snapshot)──> YNAB tracking account balance adjustment
```

See §5 for the full tracking-account lifecycle.

### 3.5 What Spendula owns vs. what YNAB owns

**Spendula owns:**

- Bank account linkage, including stable identity across re-auth cycles
- Raw transaction data (every field Enable Banking returns, preserved forever)
- Dedup state and sync history
- Mapping between Spendula bank accounts and YNAB accounts
- Exchange rate snapshots for tracking-account conversions
- Audit log of every state transition

**YNAB owns:**

- Budget structure, categories, targets, month rollovers
- Categorization (including YNAB's own payee-history learning)
- Split transactions, proper transfer pairs, scheduled/recurring transactions
- Reporting, trends, net worth
- Spouse-facing mobile UX

**Neither owns (out of scope entirely):**

- Cash transactions — entered directly in YNAB mobile
- Interactive Brokers activity — manual YNAB tracking-account updates
- Tax preparation, bill reminders

---

## 4. Data model

Laravel migrations. Standard `created_at` / `updated_at` on every table. UUIDv7 primary keys for externally-referenced entities; bigint auto-increment for internal-only tables.

### 4.1 `banks`

Reference table. Fixture banks (e.g. `mock`) are seeded from `config/spendula-banks.php` via `spendula:banks:sync`; operator banks are added directly via `spendula:banks:add` and never appear in source so this repo can ship publicly without leaking which institutions a given operator banks with.

| column | type | notes |
|---|---|---|
| `slug` | string, PK | lowercase ascii — operator-chosen, never named in source |
| `display_name` | string | e.g. `Millennium BCP` |
| `aspsp_name` | string | exact Enable Banking ASPSP name |
| `aspsp_country` | char(2) | ISO 3166-1 alpha-2 |
| `psu_type` | enum | `personal`, `business` |
| `default_currency` | char(3) | ISO 4217 |
| `sync_lookback_days` | int | per-bank configurable window (see §6.2) |

### 4.2 `bank_connections`

One row per successful authorization. Multiple rows per bank over time.

| column | type | notes |
|---|---|---|
| `id` | uuid, PK | |
| `bank_slug` | FK | |
| `enable_banking_session_id` | string | |
| `status` | enum | `active`, `superseded`, `expired`, `revoked`, `failed` |
| `authorized_at` | timestamp | |
| `valid_until` | timestamp | from Enable Banking response |
| `superseded_by_id` | uuid, nullable | self-FK; set when a newer connection replaces this one |
| `raw_session_response` | jsonb | complete `/sessions` response |
| `last_synced_at` | timestamp, nullable | |

**Invariant:** at most one `active` connection per `bank_slug` at any time. When a new authorization completes for a bank that already has an active connection, the old one moves to `superseded` and `superseded_by_id` is set, atomically.

### 4.3 `bank_accounts`

The stable representation of a bank account across consent cycles.

| column | type | notes |
|---|---|---|
| `id` | uuid, PK | |
| `bank_slug` | FK | |
| `display_name` | string | user-set during first-sight mapping |
| `iban` | string, nullable | last known; for display only |
| `currency` | char(3) | native currency |
| `is_base_currency` | bool | derived; `true` iff `currency == SPENDULA_BASE_CURRENCY` |
| `ynab_account_id` | uuid, nullable | null = unmapped |
| `ynab_account_type` | enum, nullable | `on_budget`, `tracking`; derived at mapping time |
| `import_cutoff_date` | date, nullable | transactions before this date are auto-marked `skipped` on insertion (see §6.5) |
| `active` | bool | soft-disable flag |
| `first_linked_at` | timestamp | |
| `last_seen_at` | timestamp | |

**Mapping rule:** `is_base_currency` accounts may map to YNAB on-budget or tracking accounts. Non-base-currency accounts may map **only** to YNAB tracking accounts. The mapping command enforces this.

### 4.4 `bank_account_identifiers`

Many-to-one with `bank_accounts`. Enable Banking returns both a primary `identification_hash` and an `identification_hashes` array; both go in here.

| column | type | notes |
|---|---|---|
| `id` | bigint, PK | |
| `bank_account_id` | FK | |
| `hash` | string, unique, indexed | |
| `is_primary` | bool | true for the primary `identification_hash` as of the most recent session |
| `first_seen_at` | timestamp | |
| `last_seen_at` | timestamp | |

**Matching rule:** when processing a session's accounts, for each account, collect its primary hash and all hashes from `identification_hashes`. Query for any row in `bank_account_identifiers` where `hash IN (<collected>)`. If a row is found, this is an existing account; add any new hashes we didn't know about. If no row is found, create a new `bank_account` and seed its identifiers.

### 4.5 `bank_account_sessions`

Links `bank_connections` to `bank_accounts`, storing the per-session account UID (mutable; used only within the session's lifetime for API calls).

| column | type | notes |
|---|---|---|
| `bank_connection_id` | FK, PK part | |
| `bank_account_id` | FK, PK part | |
| `enable_banking_uid` | string | session-scoped |

### 4.6 `bank_account_sync_state`

Per-bank-account operational state. Separate from `bank_accounts` because `bank_accounts` models identity; this models progress.

| column | type | notes |
|---|---|---|
| `bank_account_id` | FK, PK | |
| `last_successful_sync_at` | timestamp, nullable | wall time |
| `last_fetched_through` | date, nullable | latest `booking_date` successfully processed |
| `last_continuation_key` | string, nullable | set if pagination was interrupted mid-sync |
| `last_sync_error_at` | timestamp, nullable | |
| `consecutive_failure_count` | int, default 0 | |

### 4.7 `transactions`

The core table. State machine:

```
          ┌───────────┐
          │  fetched  │  (from sync; for on-budget accounts only)
          └─────┬─────┘
                │  review
     ┌──────────┼──────────┐
     ▼          ▼          ▼
┌────────┐ ┌────────┐ ┌──────────┐
│approved│ │skipped │ │ transfer │  (all are review outcomes)
└────┬───┘ └────────┘ └────┬─────┘
     │       terminal      │
     │                     │
     ▼                     ▼
┌──────────┐          ┌──────────┐
│  pushed  │          │  pushed  │  (via push; both states are pushed as
└──────────┘          └──────────┘  regular transactions, `transfer` gets
   terminal              terminal    a memo prefix — see §7.3)

   (Separate lifecycle for tracking accounts: status=tracking, see §5)
```

| column | type | notes |
|---|---|---|
| `id` | uuid, PK | |
| `bank_account_id` | FK | |
| `dedup_hash` | string, indexed | see §6.7 |
| `entry_reference` | string, nullable, indexed | Enable Banking's `entry_reference` when present |
| `status` | enum | `fetched`, `approved`, `skipped`, `transfer`, `pushed`, `tracking` |
| `transaction_status` | enum | `BOOK` (v1 imports only BOOK; see §6.2) |
| `booking_date` | date | |
| `value_date` | date, nullable | |
| `amount_milliunits` | bigint, signed | native currency, milliunits |
| `currency` | char(3) | native |
| `credit_debit_indicator` | enum | `CRDT`, `DBIT` |
| `counterparty_name` | string, nullable | |
| `counterparty_resolution_level` | tinyint | 0–4, see §6.8 |
| `remittance_information` | text, nullable | |
| `raw_payload` | jsonb | complete Enable Banking transaction object |
| `occurrence` | smallint, default 1 | disambiguator for same-day/amount/payee dupes; see §6.3 |
| `ynab_transaction_id` | string, nullable | after successful push |
| `ynab_import_id` | string(36), nullable | dedup key sent to YNAB |
| `push_attempt_count` | int, default 0 | |
| `last_push_attempt_at` | timestamp, nullable | |
| `last_push_error` | text, nullable | structured JSON, redacted |
| `pushed_at` | timestamp, nullable | |
| `skipped_at` | timestamp, nullable | |
| `skip_reason` | text, nullable | |
| `first_seen_at` | timestamp | when Spendula first saw this transaction |
| `last_updated_from_bank_at` | timestamp | last time the match/update algorithm touched this row |

**Uniqueness:** `UNIQUE(bank_account_id, dedup_hash, occurrence)`.

### 4.8 `auth_requests`

Short-lived rows tracking pending OAuth callbacks.

| column | type | notes |
|---|---|---|
| `id` | uuid, PK | |
| `state` | string, unique, indexed | opaque random token sent in `/auth` |
| `bank_slug` | FK | |
| `created_at` | timestamp | |
| `expires_at` | timestamp | `created_at + 15 minutes` |
| `consumed_at` | timestamp, nullable | set when the callback successfully exchanges the code |

Expired rows (age > 24h) are pruned lazily by the callback handler.

### 4.9 `sync_runs`, `sync_run_errors`

`sync_runs`:

| column | type | notes |
|---|---|---|
| `id` | bigint, PK | |
| `bank_slug` | FK, nullable | null = all banks |
| `started_at`, `finished_at` | timestamps | |
| `transactions_inserted`, `transactions_updated`, `transactions_deduped` | int | |
| `error_count` | int | |

`sync_run_errors`:

| column | type | notes |
|---|---|---|
| `id` | bigint, PK | |
| `sync_run_id` | FK | |
| `bank_account_id` | FK, nullable | |
| `error_type` | enum | `consent_expired`, `rate_limit`, `http_error`, `parse_error`, `conversion_error`, `other` |
| `error_detail` | text | redacted; IDs and shapes only |
| `http_status` | int, nullable | |
| `created_at` | timestamp | |

### 4.10 `push_runs`, `push_run_errors`

Mirror of the sync tables for YNAB pushes. `push_run_errors.error_type` enum: `validation`, `auth`, `rate_limit`, `http_error`, `network`, `other`.

### 4.11 `exchange_rates`

Cache of rates used for tracking-account conversions.

| column | type | notes |
|---|---|---|
| `base_currency`, `quote_currency` | char(3) | |
| `rate_date` | date | |
| `rate` | decimal(18,8) | |
| `source` | string | e.g. `ecb`, `frankfurter` |
| Composite unique on `(base, quote, rate_date, source)` | | |

### 4.12 `tracking_snapshots`

One row per push of a balance snapshot to a YNAB tracking account.

| column | type | notes |
|---|---|---|
| `id` | uuid, PK | |
| `bank_account_id` | FK | |
| `as_of_date` | date | |
| `native_balance_milliunits` | bigint | |
| `base_balance_milliunits` | bigint | converted |
| `exchange_rate` | decimal(18,8) | |
| `exchange_rate_source` | string | |
| `ynab_transaction_id` | string | the adjustment transaction created in YNAB |
| `pushed_at` | timestamp | |

### 4.13 Configuration

`.env`:

- `SPENDULA_ENABLE_BANKING_APP_ID`
- `SPENDULA_ENABLE_BANKING_PRIVATE_KEY_PATH`
- `SPENDULA_ENABLE_BANKING_ENV` — `sandbox` or `production`
- `SPENDULA_YNAB_ACCESS_TOKEN`
- `SPENDULA_YNAB_PLAN_ID` — the YNAB plan (formerly "budget") ID
- `SPENDULA_BASE_CURRENCY` — matches the YNAB plan's currency (expected: `EUR`)
- `SPENDULA_EXCHANGE_RATE_PROVIDER` — `ecb`, `frankfurter`, `exchangerate-host`
- `SPENDULA_CALLBACK_URL` — full URL

**Note on YNAB API naming:** YNAB renamed "budgets" to "plans" in their API on 2026-03-05. The old `/budgets/{budget_id}` paths still work for backward compatibility but are undocumented. Spendula uses the current `/plans/{plan_id}` naming throughout. Config key is `SPENDULA_YNAB_PLAN_ID`. Internal code and logs refer to "plan" not "budget".

---

## 5. Multi-currency strategy

### 5.1 Decision

**EUR accounts are on-budget. Non-EUR accounts are tracking accounts.**

- Millennium BCP (EUR) → on-budget YNAB account. Transactions pushed individually.
- Revolut EUR → on-budget YNAB account. Transactions pushed individually.
- ING Romania Personal (RON) → tracking YNAB account. Transactions stored in Spendula for audit; only balance snapshots push to YNAB.
- ING Romania Business (RON) → tracking YNAB account. Same treatment.
- UniCredit Romania (RON) → tracking YNAB account. Same treatment.

### 5.2 Rationale

- YNAB is strict about single-currency budgets. Mixing RON transactions into an EUR budget creates a permanently-unreconcilable shadow ledger: the RON account's actual balance diverges from the EUR-converted sum by exchange-rate drift.
- Tracking accounts in YNAB are explicitly designed for assets whose balance matters but whose detailed transactional activity does not affect the monthly budget (retirement accounts, investments, foreign accounts).
- The RON accounts in this household are minority volume (~15% of transactions). Not worth the complexity of per-currency budgets or shadow-ledger revaluation in v1.

### 5.3 Tracking account lifecycle

When a transaction arrives for a bank account whose `ynab_account_type = tracking`:

1. It is inserted into `transactions` with `status = tracking` (terminal). It never goes through review and is never pushed individually.
2. All fields are populated normally (counterparty, amount, dates) so the operator can query Spendula directly for foreign-currency transaction history if needed.
3. Periodically, the operator runs `spendula:tracking:snapshot [--account=X]`:
   - Compute current native-currency balance: either fetched from Enable Banking's `/accounts/{uid}/balances` endpoint (preferred), or summed from stored transactions plus last known opening balance.
   - Look up the exchange rate for today, native → EUR.
   - Compute the expected EUR balance.
   - Fetch the current EUR balance from YNAB (`GET /plans/{plan_id}/accounts/{ynab_account_id}`).
   - Compute the delta and push a single `Balance Adjustment` transaction to YNAB with:
     - `amount` = delta in EUR milliunits
     - `payee_name` = `"Balance Adjustment"`
     - `memo` = `"FX snapshot: native_balance RON, rate X, as of YYYY-MM-DD"`
     - `cleared` = `"reconciled"`
     - `approved` = `true`
   - Record a `tracking_snapshots` row.

### 5.4 Cadence

Snapshots are manual in v1. Suggested: monthly, as part of month-close. Once per quarter is acceptable; once per week is overkill. The choice is the operator's.

### 5.5 Base currency rate lookup (for tracking snapshots)

- Provider: configurable. `frankfurter.app` is the recommended default (free, no API key, ECB-sourced, good historical depth).
- Cache every rate used in the `exchange_rates` table indexed by `(base, quote, date, source)`.
- Rounding: when converting milliunits across currencies, use `bcmul` with 8 decimal rate precision, then `(int)` truncation on the final milliunit value. Document the rounding policy in code comments.
- If the rate for a specific date is unavailable (weekend, holiday): fall back to the nearest earlier business day. Store the actual `rate_date` used in `tracking_snapshots`.
- If the rate provider is unreachable entirely: the snapshot command exits with a clear error. Don't proceed with a stale rate.

### 5.6 Enable Banking bank-provided FX data

When Enable Banking returns transactions with embedded exchange rate data (`currency_exchange` field on the transaction object, populated by some banks for cross-currency transactions like Revolut card purchases abroad), prefer that over external rate lookups. Store the bank-provided rate in `raw_payload` as-is; Spendula does not act on these in v1 (all on-budget accounts are EUR, so no conversion happens at transaction level) but preserves them for future use.

---

## 6. Sync algorithm

### 6.1 High-level

`spendula:sync [--bank=slug]`:

1. Acquire advisory lock keyed to `"sync"`. Exit if locked.
2. Query `active` connections (filtered by `bank_slug` if given).
3. For each connection, for each `bank_account_sessions` row where the account is `active`:
   - Determine the fetch date range (§6.2).
   - Fetch transactions page by page via `continuation_key` (§6.6).
   - Filter to `transaction_status = BOOK` (§6.2).
   - For each transaction, run match-update-or-insert (§6.3).
4. Write a `sync_runs` row. Release the lock.

### 6.2 Fetch window and filtering

**Window:**

- If `bank_account_sync_state.last_fetched_through` is null (first sync): fetch from `today - bank.sync_lookback_days`.
- Otherwise: fetch from `max(last_fetched_through - 7, today - bank.sync_lookback_days)`.
- The 7-day overlap protects against delayed bookings and same-day status changes. The lookback cap prevents an unbounded first-sync against Millennium (which only retains 90 days anyway).

**Per-bank defaults:**

| bank | `sync_lookback_days` | notes |
|---|---|---|
| millennium | 85 | Millennium caps at 90; leave margin |
| ing_ro_personal | 365 | EB promises 730; 365 is plenty |
| ing_ro_business | 365 | |
| unicredit_ro | 365 | |
| revolut | 180 | |

**Status filtering:**

Enable Banking returns transactions with `transaction_status` in `{BOOK, PDNG, INFO, …}`. v1 processes **only `BOOK`** (booked/accounted transactions). Pending transactions are unstable: they can disappear, change amount, or be replaced by a different `BOOK` row. Handling pending is a v2 concern.

### 6.3 Match-update-or-insert

For each incoming BOOK transaction, do NOT simply hash-and-insert. Instead:

**Step 1 — try to match by `entry_reference`.**

If the incoming transaction has a non-empty `entry_reference`, query:

```sql
SELECT * FROM transactions
WHERE bank_account_id = :bank_account_id
  AND entry_reference = :entry_reference
LIMIT 1
```

If matched: proceed to update.

**Step 2 — if no `entry_reference` or no match, try fundamentals.**

Compute a fundamentals tuple:

```
(bank_account_id, booking_date, amount_milliunits (signed), currency, credit_debit_indicator, normalized_counterparty)
```

Where `normalized_counterparty` is the raw pre-resolution counterparty name, lowercased, whitespace-collapsed, non-alphanumerics stripped. If empty, use empty string.

Query for a matching row where all these fields equal. If exactly one row matches, that's our transaction; proceed to update.

If multiple rows match (legitimate same-day duplicates), the incoming transaction is a "new occurrence of a recurring-looking event." Determine the highest existing `occurrence` for the match set and insert a new row with `occurrence = max + 1`.

**Step 3 — if no match at all, insert.**

New row with `occurrence = 1`.

### 6.4 What "update" means

When a match is found, the following fields may be updated from the incoming transaction:

- `counterparty_name`, `counterparty_resolution_level` (re-run resolution in case data got better)
- `remittance_information`
- `value_date`
- `raw_payload` (always overwrite with latest)
- `last_updated_from_bank_at` (set to now)

The following fields are **never** updated after first insertion:

- `status` — once the operator has approved/skipped/transferred, we don't regress
- `amount_milliunits`, `currency`, `credit_debit_indicator`, `booking_date` — if these genuinely change (which would be unusual for a BOOK transaction), that indicates a bank data anomaly and should raise a `sync_run_errors` row for human attention
- `dedup_hash`, `occurrence`

If the amount or date did change on a transaction already past `fetched` (i.e., the operator already approved it and we already pushed), log a warning with both old and new values and skip the update. The operator resolves manually in YNAB.

### 6.5 Import cutoff

Each `bank_account` has an `import_cutoff_date` set during initial mapping. Transactions with `booking_date < import_cutoff_date` are inserted with `status = skipped` and `skip_reason = "before import cutoff"` directly, bypassing the review queue. This prevents double-import of history the user already has in YNAB from prior periods.

For tracking accounts, the cutoff applies equivalently: pre-cutoff transactions are inserted with `status = skipped` rather than `status = tracking`.

### 6.6 Pagination

Enable Banking's `/accounts/{uid}/transactions` returns a `continuation_key` when more pages exist. Spendula MUST:

1. Start with no `continuation_key`.
2. Process every page, persisting each transaction immediately (not accumulating in memory).
3. Fetch the next page using the returned `continuation_key`.
4. Stop when `continuation_key` is absent or null.
5. If sync is interrupted mid-pagination (crash, rate limit, network), store the last-seen `continuation_key` in `bank_account_sync_state.last_continuation_key` and resume from there on the next sync.

Rate limits: Enable Banking imposes per-account-per-endpoint limits (historically ~4/day for transactions). For weekly manual syncs, this is not a concern. If a 429 is received mid-pagination, persist the continuation key and abort cleanly.

**Testing pagination in phase 1**: Mock ASPSP seeds only one transaction per account by default. To exercise pagination without real bank data, create multiple mock accounts via the Enable Banking control panel (`cp/mock-aspsp`) and seed each with distinct transactions. Alternatively, recorded fixtures from sandbox Nordea (not Mock — its semantic quirks are documented in `spike/FINDINGS.md`) should be used for unit testing the match-update-or-insert algorithm across paginated responses.

### 6.7 Dedup hash

`dedup_hash` is stored for **traceability and audit**, not as the primary match key. It's a convenience for debugging ("did I already see this?") and as a fallback index.

```
hash_input = bank_account_id + "|" + booking_date + "|" + amount_milliunits_signed + "|" + currency + "|" + credit_debit_indicator + "|" + normalized_counterparty + "|" + (entry_reference or "")
dedup_hash = substr(sha256(hash_input), 0, 32)
```

The uniqueness constraint is `UNIQUE(bank_account_id, dedup_hash, occurrence)`.

### 6.8 Counterparty resolution ladder

For each transaction, try in order and record which level succeeded in `counterparty_resolution_level`:

- **Level 0** — direction-correct field:
  - `CRDT` (money in) → `debtor.name` (the other party is the debtor)
  - `DBIT` (money out) → `creditor.name` (the other party is the creditor)

  The resolved name is then passed through the bank's optional `name_rules` cleanup pipeline (same Rule schema and first-match-wins semantics as the L2 `rules` list, only the input string and the call site differ). Used for banks like Revolut that emit dirty merchant strings into structured creditor/debtor fields. The rewrite is cleanup at the same level — `counterparty_resolution_level` stays 0.
- **Level 1** — direction-inverted (covers banks that report incorrectly, notably Mock ASPSP and some RO banks):
  - `CRDT` → `creditor.name`
  - `DBIT` → `debtor.name`

  Same `name_rules` cleanup as L0 applies; level stays 1.
- **Level 2** — extract a counterparty from `remittance_information[0]`, truncated to 64 chars. Two strategies are tried in order:
  - **Structured CSV pattern** (ING RO Business and similar) — when the line looks like `Card number, **** XXXX, Transaction at, <MERCHANT>, Authorization date, …`, the merchant between `Transaction at, ` and `, Authorization date,` is pulled out directly.
  - **Prefix + suffix stripping** — strips known banking prefixes (`PURCHASE `, `POS `, `CARD PAYMENT `, `SEPA DD `, `SEPA CT `, BCP's `COMPRA NNNN ` / `TRF DE / MB WAY P / P / P O ` / `DD ` / `PAGSERV `) and the trailing ` CONTACTLESS` suffix some banks append to card-purchase lines.
- **Level 3** — fall back to `additional_information` if present, else `bank_transaction_code.description` if present. Both are EB-provided augmentation metadata; `additional_information` keeps priority because it's free-text and usually more specific, while `bank_transaction_code.description` carries the bank's posting category (e.g. `Service Fee`, `Interest adjustment`) for fee/interest entries with no other descriptor.
- **Level 4** — literal `"(Unknown)"`. Transaction is flagged with a warning icon in review CLI.

A SQL query grouping `bank_slug` by `counterparty_resolution_level` after a month of use tells us which banks have good data.

---

## 7. Review and push

### 7.1 Review CLI

`spendula:review`:

1. Acquire advisory lock `"review"`.
2. Query `transactions WHERE status = 'fetched' ORDER BY bank_account_id, booking_date, occurrence`.
3. For each transaction, print:

```
──────────────────────────────────────────────────────────
[3/47]  Millennium BCP · Main Checking · EUR
2026-04-15  −€34.57  →  PINGO DOCE AREEIRO
        resolution level 0 · entry_ref=uxr2h
────────────────────────
[a]pprove  [s]kip  [t]ransfer  [u]ndo  [d]etails  [q]uit
(uppercase = decide once, don't remember) >
```

4. Handle keypress:
   - `a` → `status = approved`, `skipped_at` cleared, move on.
   - `s` → prompt for optional reason (blank allowed), set `status = skipped`, `skip_reason`, `skipped_at`.
   - `t` → `status = transfer`, move on. The push step will prepend `[TRANSFER] ` to the memo (§7.3).
   - `A` / `S` / `T` (GH #41) → same effect on the transaction as the lowercase variant (including the skip-reason prompt for `S`), but **suppress the rule-recorder** so this decision does not generate or update a `payee_rules` row. Useful when the counterparty name is the right key for *this* row but the wrong key for a future-applying rule (e.g. ATM withdrawal whose debtor is the operator's own name; see §7.1.1). An existing rule for the same `(bank_slug, counterparty_name)` is left untouched — uppercase neither updates nor deletes it.
   - `u` / `U` → undo the most recent `a`/`s`/`t` decision in this session (LIFO). Reverts the row to `status = fetched`, clears `skip_reason`/`skipped_at`, decrements the corresponding counter, and re-queues the undone row plus the currently-displayed row at the front so the operator re-decides them in order. Stack is in-memory and unbounded within a session; rows mass-approved via `--bulk-approve-trivial` are not on it.
   - `d` / `D` → print `raw_payload` pretty-printed, then re-prompt.
   - `q` / `Q` → exit cleanly (no state change from this keypress).

5. At end, print summary.

A `--bulk-approve-trivial` flag auto-approves transactions where `counterparty_resolution_level <= 1` AND `currency = SPENDULA_BASE_CURRENCY`. Off by default; opt-in for confident operators.

#### 7.1.1 Auto-decision rules (GH #39)

Spendula remembers the operator's verdict per `(bank_slug, counterparty_name)` pair in the `payee_rules` table and auto-applies it on subsequent runs of `spendula:review`. The pipeline has three integration points:

- **Auto-create** — every interactive `a`/`s`/`t` decision in §7.1 also calls `PayeeRuleRecorder::record()`. A new rule is inserted only when none exists yet for the pair AND the rule passes guards: `counterparty_resolution_level < 4`, non-blank `counterparty_name`, and the name is not on the bank-internal denylist (`config('spendula.payee_rule_guards.bank_internal_payees')`) or the operator-name denylist (`config('spendula.payee_rule_guards.operator_names')`, populated from `SPENDULA_OPERATOR_NAMES`). The denylist comparison is case-insensitive. Mass-approved rows from `--bulk-approve-trivial` do NOT generate rules — they bypass the interactive decision path.
- **Auto-apply** — before the interactive loop opens, `PayeeRuleEngine::applyRules()` walks the `fetched` queue and routes any matching transaction through `TransactionActions` (so `skipped_at` / `skip_reason` are stamped exactly as for a manual decision). The match key is exact case-sensitive equality on `(bank_slug, counterparty_name)`. Auto-applied rows leave the `fetched` pool and are NOT re-prompted in the main loop.
- **Override** — when at least one row was auto-applied, the session opens with a summary line (`Auto-applied: N approved, M skipped, K transferred.`) and a `Show details? [y/N]` prompt. Answering `y` enters an override sub-loop over the auto-applied rows with keys `[a]pprove [s]kip [t]ransfer [k]eep [d]etails [q]uit`. Picking an action that differs from the rule's current action triggers a conflict prompt: `[u]pdate rule  [d]elete rule  [k]eep rule (one-off)`. Picking the same action as the rule (e.g. confirming an auto-approve) does not prompt.

Two artisan commands round out out-of-band rule management:

- `spendula:rules:list [--bank=<slug>]` prints `id  bank_slug  counterparty_name  action  (skip_reason)` per rule.
- `spendula:rules:delete <id>` hard-deletes one rule by UUID. Exit non-zero on missing id.

Both commands operate without the `REVIEW` advisory lock — they are operator-driven housekeeping, not transaction-mutating.

### 7.2 Push command

`spendula:push`:

1. Acquire advisory lock `"push"`.
2. Query `transactions WHERE status IN ('approved', 'transfer') AND ynab_transaction_id IS NULL AND bank_account_id IN (SELECT id FROM bank_accounts WHERE ynab_account_type = 'on_budget')`.
3. Apply retry gating: skip rows where `last_push_attempt_at > now() - interval '10 minutes'` — prevents thundering-herd retries.
4. Group by `bank_account_id`.
5. For each group, build a YNAB bulk payload (§7.3).
6. `POST /plans/{plan_id}/transactions` with `{ transactions: [...] }`.
7. Process response (§7.4).
8. Write `push_runs` row.

### 7.3 YNAB payload construction

Per transaction:

```
{
  "account_id": <ynab_account_id>,
  "date": <booking_date as YYYY-MM-DD>,
  "amount": <amount_milliunits signed>,
  "payee_name": <counterparty_name, truncated to 50>,
  "memo": <constructed memo, truncated to 200>,
  "cleared": "cleared",
  "approved": false,
  "import_id": <computed, 36 chars; see below>
}
```

**Memo construction:**

- Start with native currency/amount: `"€4.57"` if base-currency transaction; `"orig 120.00 RON"` otherwise (though this case only applies to on-budget accounts, and all on-budget accounts are EUR in v1, so this branch is effectively unused — but keep the logic for future multi-base-currency setups).
- If `status = transfer`: prepend `"[TRANSFER] "`.
- Append `" · "` + truncated `remittance_information` if non-empty.
- Truncate the final memo to 200 chars.

**`import_id` construction:**

```
import_id_input = bank_account_id + "|" + booking_date + "|" + amount_milliunits + "|" + normalized_counterparty + "|" + occurrence
import_id = "SPNDL:" + substr(sha1(import_id_input), 0, 30)
```

Total length: exactly 36. The `occurrence` field is what prevents legitimate same-day/same-amount duplicates from collapsing — two identical coffees get `occurrence = 1` and `occurrence = 2` and thus different `import_id`s.

The `SPNDL:` prefix lets the operator find and bulk-remove Spendula-originated transactions if ever needed.

### 7.4 Push response processing

YNAB responses to bulk transaction creation:

- **HTTP 201**: success or partial success. Body has `data.transactions` (created) and `data.duplicate_import_ids` (already existed).
- **HTTP 2xx but non-201**: treat as success; parse body same way.
- **HTTP 4xx**: validation error. Parse body for specific errors.

**Retry-safe failure handling:**

Transactions that fail to push stay in `status = approved` or `status = transfer`. The `push_attempt_count`, `last_push_attempt_at`, and `last_push_error` columns are updated. Next `spendula:push` run retries them (subject to the 10-minute gating).

If `push_attempt_count >= 5`, the operator is alerted via `spendula:status` output. The transaction is not auto-abandoned; the operator must investigate (might be a YNAB account deletion, might be a persistent validation issue, might be a YNAB outage).

For each response entry in `data.transactions`: find local row by `import_id`, store `ynab_transaction_id`, set `status = pushed`, `pushed_at = now()`.

For each `import_id` in `data.duplicate_import_ids`: find local row, set `status = pushed`, `pushed_at = now()`, log a note that the YNAB side already had it (probably from a prior partial-success retry — the `import_id` stability protects us here).

For any locally-approved transaction not reflected in either list: leave as-is, increment `push_attempt_count`, try again next run.

### 7.5 Why the retry-safe design matters

Partial-success failures (timeouts after YNAB has accepted some rows but before the response arrives) are the common failure mode. With stable `import_id`s and idempotent YNAB dedup, the retry correctly reconciles: second-run sees the server-side duplicates and marks them `pushed`. Without this, we'd either double-push on retry (bad) or have no automated recovery (worse).

---

## 8. Transfers (v1 minimal approach)

### 8.1 What v1 does

During review, the operator identifies a transaction as a transfer (`[t]` keypress). Spendula:

- Sets `status = transfer`.
- On push, prepends `[TRANSFER] ` to the memo.
- Pushes to YNAB as a normal transaction (not a YNAB transfer pair).

The operator, in YNAB, later converts paired `[TRANSFER]`-tagged transactions into proper YNAB transfer pairs manually. This takes seconds per pair in the YNAB web UI.

### 8.2 What v1 doesn't do

- No automatic correlation of transfer pairs.
- No pushing to YNAB as a native transfer entity.
- No handling of transfers where both sides are in different currencies.

These are v2 features (§14). The v1 choice explicitly prioritizes shipping over complete automation.

### 8.3 Why this works

A transfer from Millennium (EUR) to Revolut (EUR) lands in Spendula as two separate transactions, one on each bank account. The operator tags both as `transfer` during review. Both push to YNAB as `[TRANSFER]`-prefixed regular transactions. The net budget impact is zero (since categorization happens in YNAB, the operator would categorize them to a "Transfer" category or use YNAB's proper transfer mechanism to consolidate).

Transfers involving RON accounts are even simpler: the RON side is on a tracking account and doesn't generate individual transactions. Only the EUR side is visible and is tagged as transfer.

---

## 9. Auth flow, consent lifecycle, and HTTP

### 9.1 Initial link

1. Operator runs `spendula:auth:start millennium`.
2. Command:
   - Inserts a new `auth_requests` row with random `state`, `bank_slug`, `expires_at = now + 15 min`.
   - Calls `POST /auth` on Enable Banking with:
     - `aspsp.name`, `aspsp.country` from `banks`
     - `psu_type` from `banks`
     - `redirect_url` from `SPENDULA_CALLBACK_URL`
     - `state` from the `auth_requests` row
     - `access.valid_until = now + 90 days` (Enable Banking may reduce; we accept their value)
     - (No `access_scope` field; see spike findings — omitting it defaults to all available scopes)
   - Prints the returned `url` to the terminal.
3. Operator opens URL in browser, authenticates with bank, approves consent.
4. Browser is redirected to `https://spendula.example.com/banking/callback?code=<code>&state=<state>`.

### 9.2 Callback handler

The single HTTP route in v1. No Laravel auth middleware. Access control is:

- Tailnet-only reachability (Caddy binds only to the Tailscale interface).
- `state` validation: must match a non-consumed, non-expired row in `auth_requests`.
- Rate limiting at Caddy level: 10 req/min per source IP (prevents state-guessing attacks).

Handler logic, inside a single DB transaction:

1. Validate `state` against `auth_requests`. If missing, expired, or already consumed: return 400.
2. Mark the `auth_requests` row as consumed.
3. Call Enable Banking `POST /sessions` with `{"code": <code>}`.
4. **Immediately** persist the full response JSON to a staging `bank_connections.raw_session_response` column (before structured extraction), so if anything downstream fails we still have the data (Enable Banking returns this data only once).
5. On API error: render an error page and log a structured entry. Do NOT retry automatically.
6. On success, within the same DB transaction:
   - If there's an existing `active` connection for this `bank_slug`, transition it to `superseded` with `superseded_by_id` set.
   - Insert the new `bank_connections` row with `status = active`.
   - For each account in the response: run the identifier-matching algorithm (§4.4). Upsert `bank_accounts` as needed, add identifiers, create `bank_account_sessions` link.
   - Initialize `bank_account_sync_state` for any new accounts.
7. Render a success page listing the accounts discovered: "Connected Millennium BCP. 2 accounts discovered: Main Checking (EUR), Savings (EUR). You can close this tab."

### 9.3 No user login in v1

The `users` table is removed from the v1 schema. No Laravel auth guard, no session middleware, no login UI. The only HTTP surface is the callback, protected by state + tailnet.

When v2 adds a web review UI, real auth gets designed then.

### 9.4 Consent expiry

`bank_connections.valid_until` is set from the Enable Banking response. `spendula:status` surfaces connections expiring within 14 days with a yellow warning and within 3 days with a red warning. When sync encounters an expired connection, it transitions it to `expired` and records a `sync_run_errors` row; the operator must re-run `auth:start` to refresh.

### 9.5 Production Enable Banking setup

Production app registration is separate from sandbox. The production app is what processes real consent flows against real banks; sandbox is for development against Mock ASPSP and sandbox Nordea.

**Required form fields for the production application:**

| Field | Value |
|---|---|
| `allowed_redirect_urls` | `https://spendula.example.com/banking/callback` plus any dev fallbacks (`http://localhost:8000/banking/callback`, `https://spendula.ddev.site/banking/callback`, `https://localhost/banking/callback`) |
| Application description | Short paragraph describing Spendula as a single-user self-hosted personal-finance tool that uses AIS only (no PIS), is not offered as a service to third parties, and processes only the operator's own bank data |
| `gdpr_email` | A real monitored email address |
| `privacy_url` | A publicly-reachable URL serving a privacy policy |
| `terms_url` | A publicly-reachable URL serving terms of service |

**Hosting privacy and terms**: Privacy and terms URLs must be publicly reachable for Enable Banking's validation to succeed. Tailnet-only URLs will not pass. The recommended host is GitHub Pages (free, static, persistent). Template content for both documents is provided in the repository under `docs/legal/` and should be deployed to a separate `spendula-legal` repo on GitHub Pages before the application form is submitted.

**Activation path**: Production apps start in "pending" state. Spendula's intended activation path is the free-tier IBAN-whitelisting route (not contractual onboarding), reflecting the single-user self-hosted use case. After registration, the operator whitelists their own IBANs via the Enable Banking dashboard; once whitelisted, real bank flows work against those specific IBANs at no charge.

**Phase boundary**: Phase 1 uses **only Mock ASPSP** in the sandbox environment. Production Enable Banking setup is a **phase 2** prerequisite and is deliberately decoupled from phase-1 implementation. The README must walk the operator through production registration and IBAN whitelisting when phase 2 begins; phase 1 can ship and be validated end-to-end without it.

---

## 10. Failure modes

### 10.1 Enable Banking

| error | action |
|---|---|
| HTTP 401 (invalid JWT) | Hard fail with clear error; operator fixes `.env` / key file |
| HTTP 403 (consent revoked) | Mark connection `revoked`, log, continue with other connections |
| HTTP 429 (rate limit) | Persist `last_continuation_key`, abort this account cleanly, continue with others |
| HTTP 5xx | Retry once after 2s, then once more after 8s; if still failing, abort this account |
| Invalid `identification_hash` (null) | Log `parse_error`, skip this account, continue |
| Pagination loop (`continuation_key` doesn't advance) | Defensive: abort after 50 pages per account, log a `parse_error` |

### 10.2 YNAB

| error | action |
|---|---|
| HTTP 401 | Hard fail; operator fixes `.env` |
| HTTP 429 | Back off 60s, retry once; if still failing, abort push |
| HTTP 4xx (validation) | Record per-transaction in `push_run_errors`, leave transactions as `approved` |
| HTTP 5xx | Retry 2x with exponential backoff; if still failing, leave transactions as `approved` |
| Network timeout after request sent | Treat as retriable; next `push` run uses stable `import_id` to reconcile |

### 10.3 Exchange rate provider

| error | action |
|---|---|
| Network error | `tracking:snapshot` aborts; operator retries later |
| No rate for date | Fall back to nearest earlier business day, document in `tracking_snapshots.rate_date` |
| Provider returns empty response | Log `conversion_error`, abort the snapshot |

### 10.4 Logging policy

- **Structured only.** JSON log entries with explicit fields: `event`, `bank_slug`, `bank_account_id`, `http_status`, `error_type`, `error_detail`. Never log raw response bodies to file.
- **Raw data lives in DB.** `raw_payload`, `raw_session_response` are the single source of raw truth.
- **No PII in logs.** IBANs, account names, transaction descriptions never appear in log lines.
- **Level convention**: `debug` for flow, `info` for state transitions, `warning` for retries and anomalies, `error` for aborts.

---

## 11. Money math

- **Internal representation**: signed integer milliunits (the transaction's native currency × 1000).
- **Conversion from Enable Banking**: `"4.77"` (string) → `bcmul($amount, '1000', 0)` → `4770`. Sign derived from `credit_debit_indicator` (CRDT positive, DBIT negative).
- **Never use PHP float arithmetic.** All math uses `bcmath` or integer operations.
- **Cross-currency conversion** (tracking snapshots): `bcmul($amount_milliunits_native, $rate, 8)`, then truncate to integer. Rate is always stored with 8 decimal places.
- **Rounding policy**: truncation on final-integer milliunit output. Documented in code. Different policies (banker's rounding) can be added later if needed for reconciliation; truncation is adequate for v1.
- **Display formatting**: currency-aware decimal places.
  - EUR, USD, GBP, RON → 2 decimals
  - JPY, KRW → 0 decimals
  - BHD, KWD → 3 decimals
  - Use a `Currency::decimalPlaces(string $iso): int` helper, with a table mirroring ISO 4217's minor unit definitions. Symbols: `€` for EUR, `RON` suffix for RON (no shorter symbol), etc.

---

## 12. Testing strategy

### 12.1 Test fixture sources

Two distinct sources, used for different purposes:

- **Mock ASPSP** (sandbox) — used for **end-to-end flow validation** only. Phase 1's primary integration target. Semantically unreliable per `spike/FINDINGS.md` (creditor/debtor inversion, null `identification_hash` in some configurations, only one transaction per account by default). Fine for "does the pipe work", not fine for "does the parsing logic work".
- **Sandbox Nordea** (sandbox, but a real-bank-like ASPSP) — used to record fixtures for **correctness testing** of the match-update-or-insert algorithm, counterparty resolution ladder, pagination handling, and SEPA direction semantics. Recorded fixtures live under `tests/fixtures/enablebanking/` and are checked in.

Live API tests do not run in CI. Manual sandbox testing is the operator's responsibility before each release.

### 12.2 Unit tests

- Money math: milliunit conversions, `bcmath` edge cases, currency-aware display formatting.
- Counterparty resolution: given a fixture EB transaction, verify resolution level (tested against sandbox Nordea fixtures, not Mock).
- Dedup hash and `import_id`: stability (same input → same output), disambiguation via `occurrence`.
- Sync window computation.
- Memo construction (transfer prefix, truncation).

### 12.3 Integration tests (fixture-based)

- End-to-end sync with recorded sandbox-Nordea response including pagination. Verify DB state, including matching-not-inserting on re-sync.
- End-to-end push with recorded YNAB responses (success, duplicate, partial).
- Transfer state → memo prefix end-to-end.
- Import cutoff: transactions before cutoff auto-skipped.
- Consent supersession: new connection correctly transitions old one.

### 12.4 Smoke tests

- Every stub artisan command has a smoke test asserting it runs and exits cleanly (including the phase-1 stubs that just print "not yet implemented"). This catches accidental breakage of the command surface.

### 12.5 Static analysis

- PHPStan level 8 passes.
- Pint formatting is enforced.

---

## 13. Deployment

### 13.1 Local development

Bare metal on macOS. No containers.

- **PHP 8.4** via Homebrew.
- **PostgreSQL 18** via Homebrew, local connection on default port.
- Artisan commands invoked directly in the terminal.
- The single HTTP route (OAuth callback) runs via `php artisan serve` on `http://localhost:8000` when needed (during initial bank connection testing).
- `.env` holds local dev credentials; never committed.

Phase 1 does not require the production stack to be running locally. The production Docker build is verified by `docker compose -f docker-compose.prod.yml build` but not started locally.

### 13.2 Production

**Three-container Docker Compose stack** running on the operator's home server alongside other self-hosted services.

**Services:**

- `app` — builds from `Dockerfile` (multi-stage: Composer install → runtime on `php:8.4-fpm-alpine` with `pdo_pgsql`, `pcntl`, `opcache`, `bcmath`). No published port. Exposes port 9000 on the compose internal network.
- `web` — `nginx:alpine` with a site config that FastCGI-passes to `app:9000`. Mounts the `public/` directory from the app container. **Publishes `127.0.0.1:8765:80`** — bound to loopback only; not reachable from the LAN.
- `db` — `postgres:18-alpine` with a named volume for data. No published port. Only reachable by `app` on the compose internal network.

**Host Caddy** (already running, shared with other services) reverse-proxies `spendula.example.com` → `127.0.0.1:8765`. Caddy handles TLS via its Tailscale integration. The Caddy configuration lives on the host, not in this repo; deployment docs ship a template snippet like:

```
spendula.example.com {
    reverse_proxy localhost:8765
}
```

The operator adds this block to the host Caddyfile when first deploying.

**Why port 8765 on loopback**: keeps the stack isolated from the host network. Caddy is the sole reachability point; Docker's port publishing is minimized to a single private binding. No tailnet or LAN exposure of the raw compose services.

**Secrets**: `.env` on the host is never committed. The `app` container reads it via the standard Laravel mechanism.

**Artisan in production**: `docker compose -f docker-compose.prod.yml exec app php artisan spendula:<command>`. Wrap in a shell alias or tiny script on the host for the weekly ritual.

### 13.3 Deploy process

```
git pull
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
```

No rolling deploys, no zero-downtime requirements — single-user tool, brief downtime during migration is fine.

### 13.4 Backup

- PostgreSQL dump nightly via the host's existing backup regime (the named `db` volume is backed up as part of the host's Docker volume backup, or via `pg_dump` inside the container piped to host storage).
- Enable Banking private key file and `.env` on the host are included in backups.
- Raw transaction JSON lives in the DB (`transactions.raw_payload`); backup of the DB is backup of the data.

---

## 14. v1 scope — what ships

1. Enable Banking integration: auth, pagination, BOOK-only transactions, `identification_hashes` array matching.
2. Match-update-or-insert sync algorithm with `entry_reference` primary key.
3. Stable account identity across consent cycles via `bank_account_identifiers`.
4. Single-active-connection invariant with `superseded` lifecycle.
5. Per-bank-account sync state (`last_fetched_through`, resumable `continuation_key`).
6. Counterparty resolution ladder with telemetry.
7. Three-state review queue (Approve / Skip / Transfer) as interactive CLI.
8. Retry-safe YNAB push with stable `import_id` including `occurrence` disambiguator.
9. On-budget (EUR) handling: individual transaction push.
10. Tracking (RON) handling: transactions stored for audit, balance snapshots pushed on demand.
11. Per-bank-account import cutoff date.
12. Five pre-seeded banks (Millennium BCP, ING RO Personal, ING RO Business, UniCredit RO, Revolut).
13. Artisan commands: `banks:sync`, `auth:start`, `accounts:map`, `sync`, `review`, `push`, `status`, `convert-pending`, `tracking:snapshot`.
14. Structured error tables (`sync_run_errors`, `push_run_errors`).
15. Redacted structured logging.
16. Advisory locks on all long-running commands.
17. Transactional callback handler with raw-response-first persistence.
18. PHPStan level 8, Pint formatting, unit + fixture integration tests.
19. Caddy + Tailscale deployment.
20. Setup README covering: key generation, Enable Banking app registration (sandbox and production, including `gdpr_email` / `privacy_url` / `terms_url` requirements for production), YNAB PAT generation, database bootstrap, first auth, first sync, first push. (Satisfied by README §3 Prerequisites, §6 Production EB registration, §11 Troubleshooting.)

### 14.1 v1 non-goals (explicitly deferred)

- Web UI (review is CLI).
- Multi-user access and real authentication.
- Pending/`PDNG` transaction handling.
- Automatic transfer-pair correlation.
- CSV fallback import (historical backfill or banks not on Enable Banking).
- Interactive Brokers integration.
- LLM-assisted categorization.
- Split transactions at push time.
- Reimbursement tracking.
- Scheduled sync (cron, systemd timer).
- Webhook-based real-time updates.
- Undo push (API-based YNAB transaction deletion from Spendula).
- Mobile app.
- Budget affordability queries — YNAB's job.
- Multi-base-currency budgets (everything converges on EUR in v1).

---

## 15. v2+ roadmap

Priority will be dictated by actual v1 use. Rough ordering:

### 15.1 Near-term (first weeks of real use)

- **Web UI for review**. Laravel + Blade + htmx; no SPA. Enables mobile review and spouse participation.
- **Scheduled sync**. Laravel scheduler entry for daily syncs, nightly status email.
- **Consent expiry alerts**. Email when consent is T-14, T-7, T-1 days from expiry.
- **CSV fallback import**. For banks EB doesn't cover or for historical backfill beyond EB's 24-month window.

### 15.2 Medium-term

- **Automatic transfer pair correlation**. Detect same-amount opposite-sign transactions across accounts within N days; offer them as a linked pair in review; push as a true YNAB transfer entity.
- **Pending transaction handling**. Import `PDNG` as a separate state, resolve to `BOOK` when the bank confirms.
- **LLM categorization suggestions**. Claude Haiku suggests categories during review; operator confirms.
- **Multi-user web access**. Real auth, per-user account scoping.
- **Undo push**.
- **Interactive Brokers integration** as a tracking account with monthly snapshot automation.

### 15.3 Long-term / speculative

- **Split transactions at push time**. Supermarket split into groceries + baby + household.
- **Reimbursement tracking**. "This €500 is expected to be refunded; auto-link the inflow when it arrives."
- **Spendula as open source**. Dockerfile, plugin-based bank config, contribution docs.
- **Spendula mobile companion**. Only if YNAB's cash entry UX proves inadequate.

---

## 16. Implementation ordering (guidance for Claude Code)

The implementation is split into phases. Phase 1 ships a working end-to-end slice (one bank = Mock ASPSP, one YNAB account, sync + review + push). Later phases broaden scope to real banks, tracking accounts, and supporting commands.

### 16.1 Phase 1 — minimum viable pipe

Goal: `artisan spendula:sync` pulls transactions from Mock ASPSP into Postgres, `artisan spendula:review` lets the operator Approve/Skip/Transfer them at the terminal, `artisan spendula:push` sends approved ones to a YNAB test plan. Plus a working production Docker build.

1. **Foundation**: Laravel 13 skeleton, PostgreSQL 18 connection, `.env` + `.env.example`, logging setup, Pint + PHPStan level 8 config.
2. **Schema (all tables)**: all migrations from §4 in one pass, including tables whose features are phase-2 (`exchange_rates`, `tracking_snapshots`). Schema stability matters more than deferring tables.
3. **Banks seed**: `config/spendula-banks.php` with just `mock` for now (or all five with only `mock` marked `active: true`); `spendula:banks:sync` command.
4. **Enable Banking client**: JWT signing (extracted from spike `lib.php`), `Http`-based wrapper in `app/Services/EnableBanking/`, basic `GET /application` sanity check wired into the foundation.
5. **Auth flow**: `spendula:auth:start mock`, callback route at `/banking/callback`, `POST /sessions`, connection + account + identifier upsert. Callback renders a simple Blade success page.
6. **Account-to-YNAB mapping**: for phase 1, this is a one-off seed (inline config, direct DB insert via a dedicated artisan command `spendula:accounts:seed-mock`, or a migration). The full interactive `spendula:accounts:map` command ships as a phase-2 stub that prints "not yet implemented".
7. **Sync (on-budget only)**: implement match-update-or-insert with pagination. Mock ASPSP only. **Highest-risk step**; budget a full day; write integration tests against seeded Mock fixtures or recorded sandbox-Nordea fixtures *before* the sync logic itself.
8. **Review CLI**: Approve / Skip / Transfer / Details / Quit. Advisory lock on entry.
9. **Push**: YNAB bulk create against `/plans/{plan_id}/transactions`, retry-safe error handling, `push_runs` logging. Advisory lock on entry.
10. **Stubs for deferred commands**: `spendula:accounts:map`, `spendula:status`, `spendula:convert-pending`, `spendula:tracking:snapshot` all exist as artisan commands that print "not yet implemented (phase N)" and have passing smoke tests.
11. **Production Docker artifacts**: `Dockerfile`, `docker/nginx/default.conf`, `docker-compose.prod.yml`, `.dockerignore`. Verified by `docker compose -f docker-compose.prod.yml build`. Not started locally.
12. **Tests**: unit suite covering money math, counterparty resolution, dedup hash, `import_id`, memo construction, sync window. Integration tests covering sync idempotency, push round-trip, transfer memo prefix. Smoke tests for every artisan command including stubs.
13. **Docs**: `README.md` covering local dev setup and prod deploy; `docs/DEPLOY.md` with the host Caddy snippet template; `CLAUDE.md` at repo root orienting future sessions.

Phase 1 is complete when: (a) Mock ASPSP seeded with N>1 transactions flows cleanly through sync → review → push into YNAB, verifiable in the YNAB web UI; (b) re-running sync produces 0 inserts (idempotency); (c) `docker compose build` succeeds; (d) `php artisan test` green; (e) PHPStan level 8 green.

### 16.2 Phase 2 — real banks and production Enable Banking

Goal: replace Mock with real banks. Requires production Enable Banking app registration and IBAN whitelisting (§9.5).

14. Production Enable Banking app registration: privacy/terms pages deployed to GitHub Pages; application form submitted; IBAN whitelisting completed.
15. `spendula:accounts:map` interactive implementation.
16. Additional bank configs activated in `config/spendula-banks.php` (Millennium BCP first — highest transaction volume).
17. Recorded fixtures captured from each real bank's first sync for use in regression tests.
18. Counterparty resolution ladder validation per bank: after a week of real data, check `SELECT bank_slug, counterparty_resolution_level, count(*) FROM transactions GROUP BY 1, 2` and tune ladder if any bank sits predominantly at level 2+.

### 16.3 Phase 3 — tracking accounts and multi-currency

Goal: RON bank accounts (ING RO Personal, ING RO Business, UniCredit RO) sync into Spendula and push balance snapshots into YNAB tracking accounts.

19. Exchange rate client (default: frankfurter.app).
20. `exchange_rates` table usage; caching logic.
21. Sync path for tracking accounts: inserts with `status = tracking` instead of `fetched`.
22. `spendula:tracking:snapshot` real implementation.
23. Revolut activation (if not already on-budget-only).

### 16.4 Phase 4 — supporting commands

Goal: the full operational surface.

24. ~~`spendula:status` dashboard.~~ (done 2026-05-01, GH #16)
25. `spendula:convert-pending` real implementation. (deferred to Phase 2+; see [dlucian/spendula#23](https://github.com/dlucian/spendula/issues/23))
26. ~~Consent expiry surfacing in `status` with T-14 / T-7 / T-3 warnings.~~ (covered by `spendula:status` — T-14 yellow / T-3 red per SPEC §9.4)

### 16.5 After phase 4

At this point, v1 as specified in §14 is complete. Future work maps to the v2+ roadmap in §15.

### 16.6 Ongoing discipline

At every stage: commit frequently, run the full flow end-to-end in sandbox before moving on, keep PHPStan green, keep tests green. **Stage 7 is the make-or-break**; if the match-update-or-insert algorithm is subtly wrong, everything downstream inherits the bug.
