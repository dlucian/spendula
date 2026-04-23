# Spendula — Phased implementation plan

This plan turns `SPEC.md` into acceptance-checkable phases. Each phase has a one-line goal, explicit acceptance criteria (testable by `php artisan test`, a specific command outcome, or a manual e2e check), an out-of-scope list, and a rough size estimate.

Phases map to SPEC §16. Phase 0 covers scaffolding (done in the current session); phases 1–4 follow SPEC's ordering.

---

## Phase 0 — Scaffolding

**Goal:** Empty Laravel 13 app on PHP 8.4 + PostgreSQL 18, artisan stubs for every phase-1 command, production Docker build that actually builds. No business logic. No Enable Banking or YNAB code.

**Acceptance criteria:**

- `php -v` reports 8.4.x; `psql --version` reports 18.x.
- Laravel 13 installed into the repo root; existing `.git/`, `docs/`, `spike/`, `README.md` preserved.
- `php artisan migrate` succeeds against a local Postgres 18 database.
- `php artisan test` green on the stock suite.
- Every phase-1 artisan command exists and prints "not yet implemented" (or similar): `spendula:banks:sync`, `spendula:auth:start`, `spendula:accounts:map`, `spendula:sync`, `spendula:review`, `spendula:push`, `spendula:status`, `spendula:convert-pending`, `spendula:tracking:snapshot`, plus a phase-1 seeder (`spendula:accounts:seed-mock`). Each has a passing smoke test.
- `docker compose -f docker-compose.prod.yml build` succeeds end-to-end on the developer's Mac.
- Prod image boots far enough to run `php artisan --version` inside the `app` container.
- `.env.example` committed with every key from SPEC §4.13 present (placeholder values).
- `.gitignore` covers `vendor/`, `node_modules/`, `.env`, `storage/*.log`, `storage/keys/*`; `spike/vendor/` remains ignored.
- `README.md` updated to reflect flat layout + hybrid local/Docker model. `CLAUDE.md` at repo root. `docs/DEPLOY.md` with the host Caddy snippet template.

**Out of scope for phase 0:**

- Enable Banking client (JWT, HTTP, any API call).
- YNAB client.
- Any domain model beyond stock Laravel migrations.
- Any sync/review/push logic.
- Running the prod stack locally.

**Rough size:** one focused session.

---

## Phase 1 — Minimum viable pipe (Mock ASPSP)

**Goal:** `spendula:sync` pulls Mock ASPSP transactions into Postgres; `spendula:review` lets the operator Approve/Skip/Transfer at the terminal; `spendula:push` sends approved rows to a YNAB test plan. Re-running sync is a no-op.

**Scope corresponds to SPEC §16.1.**

### 1a. Schema (SPEC §4)

Migrations for **all** tables, including phase-2 and phase-3 tables (`exchange_rates`, `tracking_snapshots`, etc.). Schema stability matters more than deferring columns. Status enums implemented as Postgres CHECK constraints on text columns (not native Postgres `ENUM` type — these are brittle to alter).

**Acceptance:** `php artisan migrate:fresh` creates all §4 tables; PHPStan level 8 green on models.

### 1b. Banks seed (SPEC §16.1.3)

`config/spendula-banks.php` ships only the `mock` entry in phase 1. `spendula:banks:sync` reconciles the `banks` table with the config (upsert matches in the config, mark rows absent from the config as inactive rather than deleting).

**Acceptance:** running `spendula:banks:sync` twice is a no-op on the second run; removing an entry from the config on a subsequent run deactivates that row without deleting it; integration test covers both paths.

### 1c. Enable Banking client (SPEC §3.1, §9.1)

- `App\Services\EnableBanking\Jwt` — RS256 signer, isolates `firebase/php-jwt` usage, unit-tested against a generated keypair.
- `App\Services\EnableBanking\Client` — Laravel `Http`-based wrapper. Methods: `application()`, `aspsps()`, `startAuth(array)`, `exchangeCode(string)`, `accountTransactions(string $uid, ?string $dateFrom = null, ?string $continuationKey = null)`. Failure handling per SPEC §10.1.

**Acceptance:** unit tests cover JWT signing and a successful `application()` call against a mocked HTTP transport; integration test (tagged as manual/sandbox, not in CI default) verifies `application()` returns 200 against real Mock ASPSP.

### 1d. Auth flow + callback route (SPEC §9.1–9.2, §16.1.5)

- `spendula:auth:start {bank_slug}` — inserts `auth_requests` row, calls `POST /auth`, prints the returned URL to the terminal, warns that EB's session URL expires in under 10 minutes.
- `GET /banking/callback` — `BankingCallbackController@handle`. Validates `state` against `auth_requests`, exchanges code for session, persists `raw_session_response` *first*, then runs the `identification_hash` matching algorithm from SPEC §4.4 inside a single DB transaction. Renders a Blade success page listing the accounts discovered.

**Acceptance:** end-to-end: operator runs `spendula:auth:start mock`, opens the URL via `php artisan serve`, completes Mock consent, lands on the success page, `bank_connections` has one `active` row, `bank_accounts` has ≥1 row seeded with identifiers. Integration test covers supersession (second successful auth transitions the first to `superseded`).

### 1e. Account-to-YNAB mapping seeder (SPEC §16.1.6)

`spendula:accounts:seed-mock` — dedicated artisan command that, given a `--bank-account-id` and `--ynab-account-id`, sets `ynab_account_id`, `ynab_account_type`, `import_cutoff_date`, and `display_name` on an existing `bank_accounts` row. One-off tool; not the full interactive `accounts:map` (phase 2).

**Acceptance:** command succeeds against a connected Mock account; refuses to map a non-base-currency account to `on_budget` (SPEC §4.3); tested.

### 1f. Sync — on-budget path (SPEC §6, §16.1.7)

**This is the highest-risk stage.** Budget a full day. Write fixture-based tests *before* the sync logic.

- `App\Services\Sync\MatchUpdateOrInsert` — pure class implementing SPEC §6.3 (entry_reference match → fundamentals match → occurrence-disambiguated insert). Unit-tested against both Mock fixtures and sandbox-Nordea fixtures covering: new insert, exact re-sync (idempotent no-op), fundamentals-match update, same-day duplicate (occurrence bump), pre-cutoff auto-skip.
- `App\Services\Sync\SyncRunner` — orchestrates: acquires advisory lock, iterates active connections → accounts → pages, persists each page before fetching the next, resumes from `last_continuation_key` on interruption.
- `App\Services\Counterparty\Resolver` — the SPEC §6.8 ladder. Unit-tested per level.
- `spendula:sync [--bank=slug]` — thin command wrapping `SyncRunner`. Flag is a no-op on phase-1 data (single bank) but implemented for forward compat.

**Acceptance:**
- First run against Mock with N>1 seeded transactions produces N `fetched` rows.
- Second run is a pure no-op: 0 inserts, 0 updates, `sync_runs` row recorded, all rows unchanged (aside from `last_updated_from_bank_at`).
- A pre-cutoff Mock transaction (crafted via fixture or by adjusting `import_cutoff_date`) inserts as `skipped`, never enters the review queue.
- Integration test for a paginated sandbox-Nordea fixture verifies every page persists before the next fetches and that an interrupted run resumes.
- Counterparty resolution against Mock lands at level 1 (inverted), against sandbox-Nordea lands at level 0.

### 1g. Review CLI (SPEC §7.1, §16.1.8)

- `App\Services\Review\ReviewSession` — raw-mode TTY handling (`stty -icanon -echo min 1 time 0`, restored in `finally`). Keys: `a`/`s`/`t`/`d`/`q`.
- `spendula:review` — command wrapper with advisory lock, summary at end.

**Acceptance:** e2e manual test — the operator reviews the N fetched Mock transactions, one of each outcome (approve, skip-with-reason, transfer, details, quit), DB state matches keypresses. `--bulk-approve-trivial` flag honoured.

### 1h. Push (SPEC §7.2–7.5, §16.1.9)

- `App\Services\Ynab\Client` — bearer auth, auto-unwraps `{data: ...}`, uses `/plans/{plan_id}/…` throughout.
- `App\Services\Push\PushRunner` — SPEC §7.2–7.4. Groups by `bank_account_id`, builds payload, POSTs to `/plans/{plan_id}/transactions`, applies retry gating, processes both `data.transactions` and `data.duplicate_import_ids` paths.
- `spendula:push` — command wrapper with advisory lock.

**Acceptance:**
- `spendula:push` turns every `approved`/`transfer` row into a `pushed` row with `ynab_transaction_id` populated, verifiable in the YNAB web UI.
- Re-running `spendula:push` is a no-op (nothing to push; retry gating also works).
- Simulating a partial-success (by pre-populating some `import_id`s on the YNAB side) verifies the `duplicate_import_ids` path transitions local rows to `pushed`.
- Transfer-tagged rows arrive in YNAB with `[TRANSFER] ` memo prefix.

### 1i. Stubs (SPEC §16.1.10)

`spendula:accounts:map`, `spendula:status`, `spendula:convert-pending`, `spendula:tracking:snapshot` all exist as artisan commands that print "not yet implemented (phase N)" and have passing smoke tests.

**Acceptance:** each stub runs cleanly, exits 0, has a smoke test.

### 1j. Production Docker (SPEC §13.2, §16.1.11)

- `Dockerfile` — multi-stage, runtime on `php:8.4-fpm-alpine` with `pdo_pgsql`, `pcntl`, `opcache`, `bcmath`. Composer install happens in a builder stage.
- `docker/nginx/default.conf` — FastCGI pass to `app:9000`.
- `docker-compose.prod.yml` — three services (`app`, `web`, `db`), loopback-only `127.0.0.1:8765:80` publish on `web`, named volume for `db`, bind mounts of `.env` and `private.key` as `:ro` into `app`.
- `.dockerignore` — excludes `vendor/`, `node_modules/`, `.env`, `spike/`, `docs/`, `.git/`, tests.
- `docs/DEPLOY.md` — host Caddy template block, deploy steps from SPEC §13.3, rationale for port 8765.

**Acceptance:** `docker compose -f docker-compose.prod.yml build` succeeds; `app` container boots `php artisan --version` cleanly. Stack is not started locally.

### 1k. Tests + static analysis (SPEC §16.1.12)

- Unit tests: money math (milliunit conversions, `bcmath` edges, display formatting), counterparty resolution (per-level, both Mock and Nordea fixtures), dedup hash (stability, disambiguation), `import_id` (36-char guarantee, `occurrence` difference), sync window computation, memo construction (transfer prefix, truncation).
- Integration tests: sync idempotency against a recorded Nordea fixture, push round-trip (success + duplicate path), transfer memo prefix end-to-end, import-cutoff auto-skip, consent supersession.
- Smoke tests: every artisan command runs without throwing.
- PHPStan level 8 green. Pint diff clean.

### 1l. Docs (SPEC §16.1.13)

- `README.md` rewritten for flat layout + hybrid dev model.
- `docs/DEPLOY.md` with Caddy snippet template, deploy runbook.
- `CLAUDE.md` at repo root (shipped in phase 0; may need tweaks after phase 1 lands).

**Phase 1 exit criteria (SPEC §16.1 last paragraph):**

- (a) Mock ASPSP seeded with N>1 transactions flows cleanly through sync → review → push, verifiable in YNAB web UI.
- (b) Re-running `sync` produces 0 inserts.
- (c) `docker compose build` succeeds.
- (d) `php artisan test` green.
- (e) PHPStan level 8 green.

**Out of scope for phase 1:**

- Real banks (production EB app).
- Tracking-account sync path, exchange rates, balance snapshots.
- Interactive `accounts:map`.
- `status` dashboard, `convert-pending`.
- Any automation (cron, scheduler, webhooks).
- Consent-expiry warnings.

**Rough size:** stage 1f alone is a full day; the full phase is 3–5 focused sessions.

---

## Phase 2 — Real banks and production Enable Banking

**Goal:** replace Mock with real banks. Requires production EB app registration.

**Dependency:** production EB app must be approved and at least one IBAN whitelisted. This has wall-time that's outside Claude's control.

### 2a. Production EB app registration (SPEC §9.5)

- Privacy + terms pages deployed to GitHub Pages (suggested repo: `spendula-legal`). Templates in `docs/legal/` (created during this phase).
- EB production application form submitted with `allowed_redirect_urls` including `https://spendula.example.com/banking/callback`.
- IBAN whitelisting completed via the EB dashboard for the operator's real accounts.

**Acceptance:** `GET /application` against the production EB endpoint returns 200 with the production app ID.

### 2b. Interactive `spendula:accounts:map` (SPEC §3.2, §4.3)

Prompts per unmapped `bank_account` for: YNAB account selection (listed from `/plans/{plan_id}/accounts`), `display_name`, `import_cutoff_date`. Enforces mapping rules (non-base-currency → tracking only).

**Acceptance:** unit + integration tests; e2e pass against a real connected bank.

### 2c. First real bank

- Populate `config/spendula-banks.php` with Millennium BCP (highest volume) as the first real entry.
- Run auth flow against the production EB app + real bank.
- Sync first real transactions.
- Record the first-sync JSON as a regression fixture under `tests/fixtures/enablebanking/millennium/`.

**Acceptance:** transactions flow sync → review → push for Millennium; recorded fixtures cover ≥1 `BOOK` transaction with SEPA-correct direction semantics.

### 2d. Counterparty ladder validation (SPEC §16.2.18)

After a week of real Millennium data, run the `GROUP BY bank_slug, counterparty_resolution_level` query from SPEC §6.8. Tune the ladder if any bank predominantly lands ≥ level 2.

**Acceptance:** SQL query documented; tuning decisions (if any) committed with rationale.

**Out of scope for phase 2:**

- RON banks / tracking account logic (phase 3).
- Revolut activation (phase 3 or whenever ready).
- `status` dashboard (phase 4).

**Rough size:** 1–2 sessions of engineering work; production app wall-time is the gating factor.

---

## Phase 3 — Tracking accounts and multi-currency

**Goal:** RON bank accounts sync into Spendula and push balance snapshots to YNAB tracking accounts.

### 3a. Exchange rate client

- `App\Services\ExchangeRates\FrankfurterClient` (default) implementing SPEC §5.5 (weekend/holiday fallback, caching into `exchange_rates`, provider-unreachable = hard fail).
- Pluggable via `SPENDULA_EXCHANGE_RATE_PROVIDER`.

**Acceptance:** unit tests against mocked HTTP + `exchange_rates` caching verified; missing-rate fallback to nearest earlier business day works.

### 3b. Tracking sync path

- Sync path for accounts with `ynab_account_type = tracking` inserts with `status = tracking` (terminal; bypasses review).
- Pre-cutoff transactions still go to `status = skipped` (SPEC §6.5 last paragraph).

**Acceptance:** integration test with a tracking-mapped Mock or Nordea account; transactions land with `status = tracking`, never enter review queue.

### 3c. `spendula:tracking:snapshot` (SPEC §5.3)

Compute native balance (via EB balances endpoint preferred, summed transactions fallback), convert to EUR at today's rate, fetch current YNAB balance, push delta as a `Balance Adjustment` transaction, record `tracking_snapshots` row.

**Acceptance:** e2e against a real RON tracking account; repeated runs on the same day idempotent (deltas ≈ 0); snapshot row recorded.

### 3d. Connect remaining RON banks + Revolut

ING RO Personal, ING RO Business, UniCredit RO mapped as tracking. Revolut mapped as on-budget if appropriate.

**Out of scope for phase 3:**

- Pushing individual RON transactions to YNAB (explicit non-goal).
- Automatic snapshot cadence (manual only in v1).

**Rough size:** 1–2 sessions.

---

## Phase 4 — Supporting commands

**Goal:** the full operational surface.

### 4a. `spendula:status`

Dashboard: per-bank consent expiry (with T-14 yellow / T-3 red warnings per SPEC §9.4), queued transaction counts (`fetched` / `approved` / `transfer`), last sync/push wall-times, `push_attempt_count >= 5` alerts.

**Acceptance:** command renders dashboard; warning thresholds verified via fixture data.

### 4b. `spendula:convert-pending`

Real implementation of the retry path for failed currency conversions (tracking-account transactions whose push failed due to missing rates, etc.).

**Acceptance:** integration test against seeded failure state.

### 4c. README and ops polish

Final pass: setup walkthrough (sandbox and production EB registration, YNAB PAT, DB bootstrap, first auth, first sync, first push, weekly ritual script).

**v1 complete** when phase 4 ships; SPEC §14 is satisfied. Future work maps to SPEC §15.

**Out of scope for phase 4:**

- Everything in SPEC §15 (web UI, LLM categorisation, automatic transfer pair correlation, pending/PDNG handling, etc.).

**Rough size:** 1 session.

---

## Ongoing discipline (SPEC §16.6)

At every phase: commit frequently, run the full flow end-to-end in sandbox before moving on, keep PHPStan level 8 green, keep tests green. Phase 1 stage 1f (`MatchUpdateOrInsert`) is the make-or-break — every downstream feature inherits correctness or incorrectness from there.
