# CLAUDE.md

Session orientation for future Claude Code sessions working in this repo.

## What Spendula is

A self-hosted, single-user pipe between European banks and YNAB. Enable Banking (PSD2) pulls transactions in; a terminal review step gates what reaches YNAB; `spendula:push` sends approved transactions onward. YNAB remains the system of record for budgets, categories, and reporting — Spendula owns only ingestion, dedup, and approval state.

Authoritative spec: `docs/SPEC.md`. Phased implementation plan: `docs/PLAN.md`.

## Stack

- Laravel 13 on PHP 8.4
- PostgreSQL 18
- PHPUnit (no Pest)
- PHPStan level 8, Laravel Pint (default preset)
- Nginx in prod only (`nginx:alpine`), fastcgi → php-fpm
- Laravel's `Http` facade (Guzzle under the hood); `firebase/php-jwt` for EB JWT signing
- No queue, no scheduler, no SPA, no build pipeline. Artisan is the v1 interface.

## Repo layout

Flat. This folder **is** the Laravel application root.

- `app/`, `config/`, `database/`, `routes/`, `resources/`, `tests/`, `public/`, `storage/` — Laravel.
- `docs/` — `SPEC.md` (authoritative), `PLAN.md` (phased roadmap), `DEPLOY.md` (host Caddy snippet + prod run book).
- `spike/` — PHP proof-of-concept, reference-only. **Do not modify.** Its `FINDINGS.md` contains hard-won gotchas worth rereading before touching Enable Banking or YNAB code.
- `Dockerfile`, `docker-compose.prod.yml`, `docker/nginx/default.conf`, `.dockerignore` — production containerisation only.

## Deployment model

- **Local dev**: bare metal on macOS. Homebrew PostgreSQL 18, system PHP 8.4. No Docker. Artisan commands run directly; the single HTTP route runs via `php artisan serve` on `http://localhost:8000` when the OAuth callback is needed.
- **Production**: three-container Compose stack (`app`, `web`, `db`). Only `web` publishes a port, bound to `127.0.0.1:8765` on the host. The host's existing Caddy (already fronting other services, already Tailscale-integrated) reverse-proxies `spendula.example.com` → `localhost:8765`. The Caddy config lives on the server, not in this repo; `docs/DEPLOY.md` ships a template snippet the operator adds to their Caddyfile.
- Secrets on host (`.env`, `private.key`) are bind-mounted **read-only** into the `app` container.

## Phase 1 status

**Implemented** (merged on branch `phase-1`): Mock ASPSP end-to-end via six
real artisan commands — `spendula:banks:sync`, `spendula:auth:start`,
`spendula:accounts:seed-mock`, `spendula:sync`, `spendula:review`,
`spendula:push` — plus the `/banking/callback` route. Remaining commands
(`accounts:map`, `status`, `convert-pending`, `tracking:snapshot`) still
print "not yet implemented" and pass their smoke tests.

Phase-2 work starts once the production Enable Banking app is approved
(SPEC §9.5, PLAN §2a); that's the wall-time gate, not engineering.

## Conventions

- **Read `docs/SPEC.md` and `docs/PLAN.md` before implementing a feature.** The SPEC is the source of truth for behaviour; the PLAN slices it into acceptance-checkable phases.
- **`spike/` is reference only.** Do not import, modify, or depend on anything under it. Copy patterns by hand where useful (especially the JWT signer in `spike/lib.php`).
- **Secrets never committed.** `.env` is gitignored; `.env.example` ships with every key listed in SPEC §4.13. Enable Banking private key lives at `storage/keys/enablebanking.key` locally (path-configurable), also gitignored.
- **Artisan is the v1 interface.** No web review UI in v1. The one HTTP route is the EB OAuth callback.
- **Money math uses `bcmath` or integers, never PHP floats.** See SPEC §11.
- **Advisory locks on every long-running command.** Keys registered in `app/Services/Locks/AdvisoryLock.php` — see SPEC §3.2.
- **Raw EB responses are persisted** (`transactions.raw_payload`, `bank_connections.raw_session_response`). Don't drop fields "because we don't use them".
- **Callback path is `/banking/callback`** (not `/bank/callback`). Matches the registered URLs in the EB sandbox app.
- **All YNAB paths use `/plans/{plan_id}/…`**, never the deprecated `/budgets/{budget_id}/…`. Config key is `SPENDULA_YNAB_PLAN_ID`. (The spike used the old path; don't copy that.)
- **Counterparty resolution**: SEPA-correct first (`CRDT → debtor`, `DBIT → creditor`), inverted as fallback. Against Mock ASPSP everything will resolve at level 1, not 0 — this is expected.
- **Postgres session timezone is UTC.** Set via `config/database.php`'s `pgsql.timezone => env('DB_TIMEZONE', 'UTC')`. Without this, `timestampTz` columns round-trip through the server's local tz and every `expires_at` check silently drifts by the UTC offset. Don't remove it.
- **Tests run against a real Postgres** (`spendula_test` on 127.0.0.1), not SQLite. Migrations lean on `jsonb`, partial unique indexes, and CHECK constraints with Postgres-specific predicate syntax that SQLite can't honour. `RefreshDatabase` handles the per-test rollback.
- **`$this->artisan()` is a PendingCommand, not an immediate execution.** `->assertSuccessful()` only sets the expected exit code — the command runs on destruct. Chain directly (`$this->artisan(…)->assertSuccessful()`), don't capture into a variable, or DB state reads before destruct will return pre-execution values.

## Sandbox redirect URLs (already registered in the EB sandbox app)

- `http://localhost:8000/banking/callback` — used by `php artisan serve` in local dev
- `https://localhost/banking/callback`
- `https://spendula.ddev.site/banking/callback` — DDEV fallback, not currently used

Production app (phase 2) will register the Tailscale URL; see SPEC §9.5.

## When in doubt

1. Check `docs/SPEC.md`.
2. Check `spike/FINDINGS.md`.
3. Ask the operator.

---

## Architecture constraints

Hard boundaries. These are the temptations Claude Code is most likely to drift into; resist them.

- **Do not introduce a queue, scheduler, or background worker.** No Horizon, no Laravel queues, no supervisord, no `dispatch()`. The interface is artisan commands run on demand or by host cron. Adding a queue changes the operational model and the deploy model.
- **Do not build a web review UI.** `spendula:review` is the approval surface. The only HTTP route in v1 is `/banking/callback`. No Blade views, no Livewire, no Inertia, no API resources for an SPA that doesn't exist.
- **Do not add a frontend build pipeline.** No Vite, no npm, no Tailwind compile step. If a feature feels like it needs a frontend in v1, the design is wrong.
- **Never use PHP floats for money.** Use `bcmath` for arithmetic and integer milliunits at the YNAB boundary. A single `(float)` cast or `+` between strings without `bcadd` in a money path is a defect, even if tests pass against the Mock ASPSP.
- **Do not import from, modify, or take a runtime dependency on `spike/`.** Copy patterns by hand if useful. The spike's JWT signer in `lib.php` is the canonical reference, but the file is not autoloaded and must not be.
- **Do not introduce a provider interface, event bus, plugin system, or generic "bank adapter" abstraction.** Enable Banking is the only PSD2 source on the roadmap. Add an interface the second time a concrete need appears, not the first.
- **Do not call deprecated `/budgets/{budget_id}/…` YNAB endpoints.** All YNAB calls go through `/plans/{plan_id}/…`. The spike predates the March 2026 rename — its YNAB paths are stale.
- **Do not drop fields from Enable Banking responses before persisting.** `transactions.raw_payload` and `bank_connections.raw_session_response` keep the full envelope. Selective extraction lives in derived columns and the typed DTO layer, not at the persistence boundary.
- **Never silently suppress errors.** No `@`-prefixed calls, no empty `catch (\Throwable $e) {}`, no `catch { return null; }` without a comment naming the failure mode and explaining why a thrown exception is wrong here. Logging the exception and rethrowing is fine; eating it is not.
- **Do not switch tests to SQLite or remove the UTC `pgsql.timezone` config.** Both are load-bearing for correctness. SQLite cannot honour the migrations; a non-UTC Postgres session silently corrupts every `expires_at` comparison.

---

## Comprehension rules

These three rules exist because future sessions (including future Claude Code sessions) need to understand decisions and contracts that were obvious at the moment of writing and opaque a week later.

### 1. Decision logs

When making an architectural choice, rejecting an alternative, choosing a dependency, or designing non-obvious behaviour: append to the nearest `DECISIONS.md` (repo root, or per-subsystem if one exists).

Format: date, decision, alternatives considered, constraints that drove the choice, consequences (what becomes harder, what becomes easier).

Write a decision when:

- Choosing between two strategies for the dedup hash (e.g. including `entry_reference` vs. relying on `transaction_id` alone)
- Picking a retry policy for the YNAB push when the network blips mid-batch
- Designing how `superseded` connection state interacts with the single-active-connection invariant
- Resolving a Mock ASPSP behavioural quirk that diverges from how production EB will behave (and committing to which side wins)
- Deciding whether a new field gets its own column, a `jsonb` slot, or stays in `raw_payload` only

Don't write a decision for routine choices, bug fixes that the spec already prescribes, or test-only helpers.

### 2. Behavioural contracts on interfaces

Every public method on a Service, every artisan command's `handle()`, every API client method, every Repository — needs a docblock covering:

- **Success contract** — what invariants hold after a successful return, not just the return type
- **Failure modes** — which exceptions get thrown, when, and what the caller should do
- **Side effects** — DB writes, HTTP calls, file I/O, advisory lock acquisition
- **Idempotency** — safe to retry, or not, and why
- **Concurrency safety** — which advisory lock must be held, what races are possible

**Bad:**

```php
public function pushApprovedToYnab(int $batchSize = 50): int
{
    // ...
}
```

**Good:**

```php
/**
 * Push approved, not-yet-pushed transactions to YNAB in a single batch.
 *
 * Success: every transaction with status=approved and ynab_id IS NULL whose
 *   approved_at <= now() is sent to YNAB. On 200/201 the row is updated
 *   with the returned ynab_id and pushed_at; on duplicate-import-id the
 *   existing ynab_id is fetched and stored without re-creating. Returns
 *   the count actually pushed (0 if nothing was pending).
 *
 * Failure: throws YnabAuthException on 401 — caller must not retry without
 *   refreshing the token. Throws YnabRateLimitException on 429 with the
 *   Retry-After header surfaced. Throws YnabPushException on any other
 *   non-2xx; partial batches are NOT rolled back, since YNAB has already
 *   accepted the rows it returned 2xx for.
 *
 * Side effects: writes to `transactions` (ynab_id, pushed_at). Issues HTTP
 *   POST to /plans/{plan_id}/transactions. No queue, no events.
 *
 * Idempotency: safe to retry on transient failure. YNAB's import_id dedups
 *   server-side; we additionally skip rows that already have ynab_id set.
 *
 * Concurrency: must be invoked while holding the AdvisoryLock::PUSH lock.
 *   Two concurrent runs would double-push any row whose status update lost
 *   the race.
 */
public function pushApprovedToYnab(int $batchSize = 50): int
{
    // ...
}
```

The docblock is part of the contract. If the implementation drifts from it, the docblock is the bug, or the implementation is — but never both quietly.

### 3. Comprehension summary per task

Before marking any task complete, create or update `SUMMARY.md` at the repo root with four sections:

- **What changed** — files, behaviour, migrations, new commands or routes
- **Assumptions made** — explicitly call out:
  - Which Mock ASPSP behaviours were assumed to match production EB
  - Whether YNAB API responses were stubbed, replayed, or hit live
  - OAuth state assumptions (token still valid, session `expires_at` not crossed during the test run)
  - Whether the Postgres session timezone was UTC during the run
  - External quirks treated as fixed (CRDT/DBIT counterparty inversion, `identification_hash` stability across re-auth, EB pagination via `continuation_key`)
- **Blast radius** — what could break. Be specific about callers and downstream commands.
- **Open threads** — phase-2 differences deferred, edge cases left unhandled, follow-up tickets

Small changes to OAuth refresh, the dedup hash function, advisory lock keys, the milliunit/`bcmath` money path, the sync match-update-or-insert algorithm, or the `superseded` connection lifecycle are **never routine**. Even a one-line change in any of those areas requires a SUMMARY.md entry. The "I'm just renaming a variable" instinct is the bug.

---

## Coding patterns

### Follow

- **One concern per service.** `EnableBankingClient` does HTTP and JWT signing; `TransactionSyncer` does match-update-or-insert; `YnabPusher` does the push. Don't merge them "for convenience" or because the call sites currently happen to overlap.
- **Errors are exceptions, results are values.** Return typed DTOs or throw — never `[$result, $error]` tuples or sentinel `false` returns from a method whose happy path returns a real type. PHPStan level 8 will catch the union; don't suppress it, fix the design.
- **Acquire the advisory lock before any side-effecting work in long-running commands.** Keys live in `app/Services/Locks/AdvisoryLock.php`. Release in a `finally`. A command that mutates DB rows without holding its lock is broken regardless of test results.
- **Persist the raw payload first, then derive.** Write `raw_payload` and `raw_session_response` before mapping into typed columns. Future debugging — and recovery from a derivation bug — depends on the envelope still being there.
- **Convert at the boundary, not mid-pipeline.** EB amounts come in as decimal strings; YNAB takes integer milliunits. Convert on entry and exit, not in the middle. Mid-pipeline conversions are where rounding bugs live.
- **PHPStan level 8 is the floor.** Don't suppress with `@phpstan-ignore-line` without an inline comment naming either the false positive or the legitimate exception (e.g. third-party stub gap). Suppressions without comments are reviewed and removed.

### Avoid

- **Suppressing errors silently.** No `@`, no empty `catch (\Throwable) {}`, no swallowing exceptions to "keep the loop going" without an explicit, commented decision and a `Log::error` entry. If a single-row failure shouldn't abort the batch, that is a design choice that must be documented in the docblock and at the catch site.
- **Inventing product behaviour not described in `docs/SPEC.md`.** If the spec is silent, ask the operator. Do not extrapolate from the (superseded) product brief, the spike, or YNAB's API docs. The spec is narrower than the brief deliberately.
- **Duplicating spec decisions in code comments.** Enforce the invariant in code; reference `SPEC §X` for the rationale. Restating the rationale in a docblock is how it drifts out of sync with the spec.
- **Capturing `$this->artisan(...)` into a variable in tests.** The `PendingCommand` runs on destruct. Reads of DB state before the variable goes out of scope return pre-execution values. Chain assertions directly.
- **Adding "future-proof" abstractions.** Provider interfaces for the second bank, event buses for the rule engine that doesn't exist, plugin systems for the import formats that aren't on the roadmap. Wait until the second concrete need appears.
- **Mixing pre- and post-rename YNAB paths.** All paths are `/plans/{plan_id}/…`. The spike's `/budgets/...` references are stale; don't grep-and-paste from there.
