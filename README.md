# Spendula

A self-hosted, single-user pipe between European banks and YNAB. Enable Banking (PSD2) pulls transactions in; a terminal review step gates what reaches YNAB; `spendula:push` sends approved transactions onward. YNAB remains the system of record for budgets, categories, and reporting — Spendula owns only ingestion, dedup, and approval state.

## 1. Stack

- Laravel 13 on PHP 8.4
- PostgreSQL 18
- PHPUnit, PHPStan level 8, Laravel Pint
- Nginx in prod only (`nginx:alpine`), FastCGI → php-fpm
- No queue, no scheduler, no SPA, no build pipeline. Artisan is the v1 interface.

## 2. Documentation

- [`docs/SPEC.md`](docs/SPEC.md) — authoritative product spec.
- [`docs/PLAN.md`](docs/PLAN.md) — phased implementation plan (phase 1 → phase 4).
- [`docs/DEPLOY.md`](docs/DEPLOY.md) — production deployment run book and host Caddy template.
- [`CLAUDE.md`](CLAUDE.md) — session orientation for future Claude Code runs in this repo.
- `spike/` — original PoC that proved end-to-end feasibility. Reference-only; **do not import or modify**. `spike/FINDINGS.md` has gotchas worth reading before touching Enable Banking or YNAB code.

## 3. Prerequisites

Before you start, you need:

- **PHP 8.4** and **PostgreSQL 18** on the local machine (Homebrew on macOS, or your distro's equivalent).
- A **YNAB account** with at least one budget plan, plus a **YNAB Personal Access Token** generated under *Account Settings → Developer Settings → New Token*. The plan ID is the UUID in the URL when you have the plan open in the YNAB web app.
- An **Enable Banking sandbox application** registered at <https://enablebanking.com/cp/> (free tier is fine for the sandbox flow). The sandbox app must list `http://localhost:8000/banking/callback` under *Allowed redirect URLs*.
- For the production EB flow against real banks, an additional **Enable Banking production application** is required. See [§6](#6-local-development-against-the-production-eb-app) — registration has wall-time outside Spendula's control (SPEC §9.5).

The repository never ships with operator credentials. Every secret listed in `.env.example` (SPEC §4.13) has to be filled in by you locally.

## 4. First-time setup

Bare metal on macOS. No containers in dev.

```bash
# 1. Install PHP 8.4 and PostgreSQL 18 via Homebrew.
brew install php@8.4 postgresql@18
brew services start postgresql@18

# 2. Clone and install dependencies.
git clone <repo-url> spendula
cd spendula
composer install

# 3. Bootstrap .env (DB credentials, app key) before any artisan command
#    that talks to Postgres. phpunit.xml deliberately leaves DB_USERNAME /
#    DB_PASSWORD unset, so `.env` is the single source of truth for the
#    local role; `php artisan migrate` also reads it.
cp .env.example .env
$EDITOR .env   # at minimum: DB_USERNAME, DB_PASSWORD
php artisan key:generate

# 4. Create the dev + test databases and run migrations.
createdb spendula_dev
createdb spendula_test
php artisan migrate
php artisan test   # expect: green

# 5. Fill in the rest of .env (sandbox EB credentials + YNAB PAT + plan id).
$EDITOR .env

# 6. Place the Enable Banking private key.
#    Path is configurable via SPENDULA_ENABLE_BANKING_PRIVATE_KEY_PATH.
mkdir -p storage/keys
cp /path/to/your/enablebanking.key storage/keys/enablebanking.key
chmod 600 storage/keys/enablebanking.key
```

A green `php artisan test` after the migrate step confirms the toolchain is healthy before you start chasing EB / YNAB credential issues. The remaining `.env` keys (EB app id, YNAB PAT, YNAB plan id) only matter once you reach the sandbox or production walkthroughs below.

### Counterparty cleanup rules

Spendula ships with bank-specific cleanup rules at `config/counterparty-rules-available/`. For these to take effect during sync, enable them once after a fresh clone:

```bash
php artisan spendula:counterparty:rules:enable --all
```

This creates symlinks in `config/counterparty-rules-enabled/` (gitignored). To opt out of a specific bank's rules, run `php artisan spendula:counterparty:rules:disable <bank>`. To verify everything is wired up correctly: `php artisan spendula:counterparty:rules:test`.

The one HTTP route (the EB OAuth callback) runs via `php artisan serve`:

```bash
php artisan serve
# → http://localhost:8000/banking/callback is reachable
```

The EB **sandbox** app is already configured with `http://localhost:8000/banking/callback` as an allowed redirect URL. The production EB app needs HTTPS termination — see [§6](#6-local-development-against-the-production-eb-app).

## 5. Sandbox first run (Mock ASPSP)

Mock ASPSP is the in-sandbox fake bank Enable Banking ships for testing. Use it to prove your install end-to-end before pointing at real banks.

```bash
# 1. Seed the banks catalogue (mock fixture from config/spendula-banks.php).
php artisan spendula:banks:sync

# 2. In a second terminal, start the local web server so the OAuth callback lands.
php artisan serve

# 3. Start the EB consent flow. Opens a URL; complete it in a browser.
php artisan spendula:auth:start mock

# 4. After the callback success page, map the discovered account to a YNAB
#    account. seed-mock is the scripted single-row mapper — exactly the
#    right fit for a controlled mock environment.
#    Get ynab_account_id from `https://api.ynab.com/v1/plans/{plan_id}/accounts`.
php artisan spendula:accounts:seed-mock \
    --bank-account-id=<uuid-from-callback-page> \
    --ynab-account-id=<uuid-from-ynab> \
    --display-name="Mock checking" \
    --import-cutoff-date=2026-01-01

# 5. Sync transactions.
php artisan spendula:sync

# 6. Review them in the terminal. a=approve, s=skip, t=transfer, u=undo,
#    d=details, q=quit.
php artisan spendula:review

# 7. Push approved ones to YNAB.
php artisan spendula:push

# 8. Verify the dashboard. --include-mock surfaces the mock bank's rows
#    (bare `spendula:status` filters them out by default — see §10).
php artisan spendula:status --include-mock
```

Mock ASPSP ships with zero seeded accounts — create at least one at
<https://enablebanking.com/cp/mock-aspsp> before step 3, or the consent flow
will silently error (see `spike/FINDINGS.md` #1).

## 6. Local development against the production EB app

The sandbox EB app accepts `http://localhost:8000/banking/callback`. The
production app only accepts `https://…` redirect URLs, so working with real
bank consents from your local machine needs HTTPS termination in front of
`php artisan serve`. Recipe (uses Caddy on macOS — adapt to your own
reverse proxy and OS as needed; substitute your own hostname for
`prod.spendula.example`):

```bash
# 1. Caddy with internal CA — terminates TLS, proxies to Laravel.
brew install caddy
cat > /opt/homebrew/etc/Caddyfile <<'EOF'
{
    auto_https disable_redirects
}

prod.spendula.example:8443 {
    tls internal
    reverse_proxy 127.0.0.1:8000
}
EOF

# 2. /etc/hosts pin so the hostname resolves locally to 127.0.0.1.
echo '127.0.0.1 prod.spendula.example' | sudo tee -a /etc/hosts
sudo dscacheutil -flushcache && sudo killall -HUP mDNSResponder

# 3. Start Caddy and trust its local CA in the system keychain.
sudo brew services start caddy
sudo /opt/homebrew/opt/caddy/bin/caddy trust

# 4. Verify (Laravel does NOT need to be running yet — expect 502 until it is).
curl -sI https://prod.spendula.example:8443/
```

Port `:8443` (instead of `:443`) sidesteps a conflict with DDEV's traefik
router, which holds `127.0.0.1:443` whenever any DDEV project is running.
Most OAuth providers — Enable Banking included — accept non-default ports as
long as the URL matches the registered redirect URL **exactly**.

Then point `.env` at the production EB app:

```bash
APP_URL=https://prod.spendula.example:8443
SPENDULA_CALLBACK_URL=https://prod.spendula.example:8443/banking/callback
SPENDULA_ENABLE_BANKING_APP_ID=<production-app-id>
SPENDULA_ENABLE_BANKING_ENV=production
SPENDULA_ENABLE_BANKING_PRIVATE_KEY_PATH=storage/keys/enablebanking.key
```

Drop the production private key in at the configured path. To keep both
sandbox and production keys around, stash the sandbox key as
`storage/keys/enablebanking-sandbox.key` and copy whichever you want active
to `storage/keys/enablebanking.key`. Run `php artisan config:clear` after
any `.env` edit so the new value lands.

Confirm credentials before the first auth flow:

```bash
php artisan tinker --execute="\
  \$c = app(\App\Services\EnableBanking\Client::class); \
  \$app = \$c->application(); \
  echo \$app['name'].' / '.\$app['environment'].PHP_EOL;"
# → Spendula / PRODUCTION
```

Register `https://prod.spendula.example:8443/banking/callback`
as an allowed redirect URL in the EB production app dashboard, then run
`php artisan spendula:auth:start <bank>` as usual. Heads up: EB consent
sessions expire in under 10 minutes, so be ready to complete the consent
in the browser straight away.

## 7. Adding real banks to the catalogue

`config/spendula-banks.php` ships only the `mock` fixture by design — this
repo is public, and listing operator banks in source would leak which
institutions a given operator banks with. Real banks therefore have to be
inserted directly into the `banks` table by `spendula:banks:add` (SPEC §4.1).

`spendula:auth:start <slug>` hard-fails on an unknown slug, so this step
runs **before** the first real-bank consent.

For example, to register Millennium BCP (Portugal, EUR) — one of the banks
this repo ships counterparty rules for — under the slug `bcp`:

```bash
php artisan spendula:banks:add \
    --slug=bcp \
    --display-name="Millennium BCP" \
    --aspsp-name="Millennium BCP" \
    --aspsp-country=PT \
    --default-currency=EUR
```

`--aspsp-name` must match the Enable Banking `/aspsps` value **exactly**
(case-sensitive). Pull the canonical name with:

```bash
php artisan tinker --execute="\
  foreach (app(\App\Services\EnableBanking\Client::class)->aspsps('PT') as \$a) { \
    echo \$a['name'].PHP_EOL; \
  }"
```

For a business account, add `--psu-type=business`. `--sync-lookback-days` is
optional (defaults to 90); shorten it for high-volume accounts where you
don't need a long backfill window.

## 8. YNAB starting balance vs. import cutoff date

**Read this before doing the first real-bank mapping.** YNAB's "Starting
Balance" is a one-time transaction it auto-inserts when you create an
account. After that, every imported transaction modifies the running
total. If you enter **today's** bank balance as the starting balance AND
Spendula then imports a month of historical transactions, YNAB
double-counts:

```text
YNAB balance = (starting balance) + sum(imported transactions)
```

So pick the starting balance to align with the cutoff date:

- **Backfilling history** (cutoff in the past, e.g. `2026-04-01`): set
  YNAB's starting balance to the bank's balance **as of `cutoff_date − 1`**
  (end-of-day 2026-03-31 in this example). Spendula's imports then move
  the balance from that historical figure to today's actual balance, and
  the math reconciles.
- **No backfill** (cutoff = today): set YNAB's starting balance to
  today's bank balance. You lose pre-today history but the balance stays
  correct from now on.

If you've already created the YNAB account with the wrong figure, edit
the auto-generated "Starting Balance" transaction to the correct date
and amount before running the first sync — YNAB recomputes the running
total on save.

## 9. Real-bank flow

With the production EB recipe ([§6](#6-local-development-against-the-production-eb-app))
in place, the bank in the catalogue ([§7](#7-adding-real-banks-to-the-catalogue)),
and the YNAB starting-balance gotcha ([§8](#8-ynab-starting-balance-vs-import-cutoff-date))
acknowledged:

```bash
# 1. Start the EB consent flow for the real bank.
php artisan spendula:auth:start bcp

# 2. After landing on the callback success page, map the discovered
#    account(s) to YNAB. Interactive — walks every unmapped row and
#    prompts for a YNAB target, display name, and import_cutoff_date.
php artisan spendula:accounts:map

# 3. Sync, review, push.
php artisan spendula:sync
php artisan spendula:review
php artisan spendula:push

# 4. Dashboard. Bare `spendula:status` filters mock-bank rows out by
#    default — see §10.
php artisan spendula:status
```

For automation (or to bypass the prompts) `accounts:map` accepts
`--bank-account-id=<uuid> --ynab-account-id=<uuid>` and operates on a
single row; pass either UUID alone to scope to that bank account or YNAB
account.

## 10. Tracking accounts (multi-currency)

When the YNAB plan is single-currency (e.g. EUR) but the bank account is
in another currency (e.g. RON), individual transactions cannot be pushed
because YNAB has no native multi-currency support per account. Spendula
handles this via **tracking accounts**: the foreign-currency bank account
maps to a YNAB *tracking* account (`on_budget=false`), individual
transactions are stored locally for audit but never pushed, and balance
snapshots are pushed instead.

```bash
# 1. Map the foreign-currency bank account interactively. Because the
#    bank account isn't in the plan's base currency, accounts:map
#    automatically restricts the YNAB target list to tracking accounts
#    only (on_budget=false in YNAB).
php artisan spendula:accounts:map

# 2. After sync, push a balance snapshot to YNAB.
#    --account=<spendula-uuid> scopes to one bank account; omit it to
#    snapshot every active tracking-mapped account in one run.
php artisan spendula:tracking:snapshot

# Optional: --dry-run prints the deltas without writing to YNAB or
# tracking_snapshots.
php artisan spendula:tracking:snapshot --dry-run
```

Suggested cadence: **monthly, at month-close** (SPEC §5.4). Same-day
re-runs are idempotent — the second run computes a delta of zero and
records a no-op snapshot row. There is no scheduler in v1; the operator
runs the command on demand.

If you try to map a foreign-currency bank account to an `on_budget=true`
YNAB account, `accounts:map` refuses with a SPEC §4.3 error message.

## 11. Weekly ritual

Copy-paste the snippet below into a shell once a week (or whenever the
review queue feels full):

```bash
php artisan spendula:sync \
  && php artisan spendula:review \
  && php artisan spendula:push
php artisan spendula:status   # always run, regardless of pipeline outcome
```

The two-stage shape is deliberate. The pipeline (`sync && review && push`)
short-circuits on partial failure — exactly when `spendula:status` is most
diagnostic — so the dashboard runs **unconditionally** on the next line.

`spendula:status` exit codes (per [#16](https://github.com/dlucian/spendula/issues/16)):

- **Exit 1** on any of: red consent (≤3 days remaining or expired),
  push-stuck rows (`push_attempt_count >= 5` and not yet pushed), or
  stale-sync (no successful sync for >24h on an active connection).
- **Exit 0** on yellow consent (≤14 days remaining — informational, not
  actionable) or all-clear.

`spendula:status` is a manual trigger. v1 does not include a cron / systemd
timer surface (SPEC §14.1, §15).

## 12. Troubleshooting

### Consent expired / red

Sample `spendula:status` output when a consent has lapsed:

```text
Consent: bcp  red  expired 2 days ago
```

Re-run the consent flow. The old `bank_connections` row transitions to
`superseded` and a new `active` row replaces it; bank account identity
is preserved across the re-auth via `bank_account_identifiers` (SPEC §4.4).

```bash
php artisan spendula:auth:start bcp
# Walk consent in the browser. Status flips back to green once the new
# connection lands.
php artisan spendula:status
```

### Push stuck (push_attempt_count >= 5)

`spendula:status` lists every row in the warnings section with its bank,
`bank_account_id`, amount, last error, and last attempt time. Inspect the
underlying error before retrying:

```bash
php artisan spendula:status   # warnings list rows + last error
# Investigate (often: stale YNAB token, account closed in YNAB, missing
# rate, etc.). Re-run push once the cause is resolved:
php artisan spendula:push
```

If a row is fundamentally unpushable (e.g. the YNAB account it points at
was deleted), the operator's recourse is a one-off DB update (mark it
`status = skipped` with a reason) — there is no automatic retry-cap-bypass
in v1.

### Real-bank consent failing on first try

Verify the EB environment the client is configured for. Sandbox vs.
production is a frequent source of confusion when both keys are stashed
locally:

```bash
php artisan tinker --execute="\
  echo app(\App\Services\EnableBanking\Client::class)->application()['environment'];"
# → SANDBOX or PRODUCTION
```

Run `php artisan config:clear` after every `.env` edit. EB consent
sessions expire in under 10 minutes — if the URL has been sitting open,
re-run `spendula:auth:start <slug>` to get a fresh one.

### YNAB starting-balance gotcha

YNAB's auto-inserted "Starting Balance" transaction must align with the
import cutoff date, or YNAB double-counts on the first sync. See
[§8](#8-ynab-starting-balance-vs-import-cutoff-date) for the recipe.

### Counterparty resolution looks wrong

After a week of real data, run the SPEC §6.8 audit query and re-run the
ladder for a single bank if the heuristics need tuning:

```bash
php artisan spendula:counterparty:recompute --bank=<slug>          # one bank
php artisan spendula:counterparty:recompute --bank=<slug> --dry-run # preview only
php artisan spendula:counterparty:recompute                         # all banks
```

This reruns the resolution ladder over every transaction in scope
without re-fetching from EB; safe to run repeatedly.

## 13. Artisan commands

Implemented:

| Command | Purpose |
|---|---|
| `spendula:banks:sync` | Reconcile `banks` with `config/spendula-banks.php` (mock fixture only — never touches operator-added rows). |
| `spendula:banks:add` | Insert an operator bank into the `banks` table directly. Operator banks never appear in source. |
| `spendula:auth:start {bank_slug}` | Start an EB consent flow; prints the URL to open. |
| `spendula:accounts:map` | Interactive YNAB-account mapper; walks unmapped rows or maps a single one with `--bank-account-id` + `--ynab-account-id`. Prod path. |
| `spendula:accounts:seed-mock` | Scripted single-row mapper. Useful for tests / CI; production-style ops uses `spendula:accounts:map` instead. |
| `spendula:sync [--bank=slug]` | Pull new transactions from Enable Banking. |
| `spendula:review [--bulk-approve-trivial]` | Terminal queue: Approve / Skip / Transfer / Undo. |
| `spendula:push` | Send approved transactions to YNAB. |
| `spendula:status [--include-mock]` | Dashboard: consent, queued counts, last sync/push, push-stuck warnings. Exits 1 on red consent, push-stuck, or stale-sync. |
| `spendula:tracking:snapshot [--account=id] [--dry-run]` | Push tracking-account balance snapshots to YNAB. |
| `spendula:counterparty:recompute [--bank=<slug>] [--dry-run]` | Rerun the counterparty resolution ladder over every transaction (optionally scoped to one bank slug); useful after upgrading the heuristics in `app/Services/Counterparty/`. |
| `spendula:counterparty:rules:add [--bank=<slug>] [--from-transaction=<id>]` | Interactive: add a counterparty cleanup rule. Validates regex + fixture before saving. With `--from-transaction`, pulls a real remittance and previews impact on existing transactions. |
| `spendula:counterparty:rules:enable [<bank>] [--all]` | Enable a bank's cleanup rules by creating a symlink in `config/counterparty-rules-enabled/`. Pass `--all` to enable every available rule file at once (recommended after a fresh clone). |
| `spendula:counterparty:rules:disable <bank>` | Disable a bank's cleanup rules (removes the symlink; doesn't delete the rule file). |
| `spendula:counterparty:rules:test [--bank=<slug>]` | Run every rule fixture in `config/counterparty-rules-available/`. Same logic as the auto-discovered phpunit test. |

Phase-2+ stubs (ship as "not yet implemented"):

| Command | Phase |
|---|---|
| `spendula:convert-pending` | 4 (retry failed FX conversions) [^convert-pending] |

[^convert-pending]: Stub. Pending Phase 2+ multi-currency on-budget work — see [dlucian/spendula#23](https://github.com/dlucian/spendula/issues/23) for the deferred follow-up.

See [`docs/PLAN.md`](docs/PLAN.md) for phase-by-phase acceptance criteria.

## 14. Production deployment

Three-container Docker Compose stack (`app`, `web`, `db`) behind the host's existing reverse proxy, which fronts `spendula.example.com` → `127.0.0.1:8765`.

See [`docs/DEPLOY.md`](docs/DEPLOY.md) for the deploy run book, the host Caddy snippet template, and the backup recipe.

## 15. Conventions

- **Read [`docs/SPEC.md`](docs/SPEC.md) and [`docs/PLAN.md`](docs/PLAN.md) before implementing a feature.** SPEC is the source of truth for behaviour; PLAN slices it into acceptance-checkable phases.
- **`spike/` is reference only.** Don't import, modify, or depend on it. Copy patterns by hand where useful (especially the JWT signer in `spike/lib.php`).
- **Secrets never committed.** `.env` is gitignored; `.env.example` is the schema reference. EB private key lives at `storage/keys/enablebanking.key` (also gitignored).
- **Callback path is `/banking/callback`** — matches the registered URLs in the EB sandbox app.
- **Money math uses `bcmath` or integers, never floats.** See SPEC §11.
- **YNAB paths use `/plans/{plan_id}/…`**, never the deprecated `/budgets/{budget_id}/…`.

## 16. v1 complete — SPEC §14 satisfied

Phase 4 ships v1 as specified in SPEC §14. Future work maps to SPEC §15.

| # | SPEC §14 bullet | Satisfied by |
|---|---|---|
| 1 | Enable Banking integration: auth, pagination, BOOK-only transactions, `identification_hashes` array matching | `App\Services\EnableBanking\Client`, `App\Http\Controllers\BankingCallbackController` |
| 2 | Match-update-or-insert sync algorithm with `entry_reference` primary key | `App\Services\Sync\MatchUpdateOrInsert` |
| 3 | Stable account identity across consent cycles via `bank_account_identifiers` | `bank_account_identifiers` table + callback handler |
| 4 | Single-active-connection invariant with `superseded` lifecycle | `bank_connections` superseded transition in callback handler |
| 5 | Per-bank-account sync state (`last_fetched_through`, resumable `continuation_key`) | `App\Services\Sync\SyncRunner` |
| 6 | Counterparty resolution ladder with telemetry | `App\Services\Counterparty\Resolver` |
| 7 | Three-state review queue (Approve / Skip / Transfer) as interactive CLI | `spendula:review` (`App\Services\Review\ReviewSession`) |
| 8 | Retry-safe YNAB push with stable `import_id` including `occurrence` disambiguator | `App\Services\Push\PushRunner` |
| 9 | On-budget (EUR) handling: individual transaction push | `spendula:push` |
| 10 | Tracking handling: transactions stored for audit, balance snapshots pushed on demand | `spendula:tracking:snapshot` ([§10](#10-tracking-accounts-multi-currency)) |
| 11 | Per-bank-account import cutoff date | `bank_accounts.import_cutoff_date` + sync auto-skip |
| 12 | Pre-seeded banks (operator-added via `spendula:banks:add`; `mock` fixture in source) | [§7](#7-adding-real-banks-to-the-catalogue) |
| 13 | Artisan commands: `banks:sync`, `auth:start`, `accounts:map`, `sync`, `review`, `push`, `status`, `convert-pending`[^convert-pending], `tracking:snapshot` | [§13](#13-artisan-commands) |
| 14 | Structured error tables (`sync_run_errors`, `push_run_errors`) | migrations under `database/migrations/` |
| 15 | Redacted structured logging | `App\Logging` redaction processors |
| 16 | Advisory locks on long-running commands (carve-outs: `accounts:map`, `status`) | `App\Services\Locks\AdvisoryLock` |
| 17 | Transactional callback handler with raw-response-first persistence | `BankingCallbackController` |
| 18 | PHPStan level 8, Pint formatting, unit + fixture integration tests | `phpstan analyse` / `pint --test` / `php artisan test` |
| 19 | Caddy + Tailscale deployment | [`docs/DEPLOY.md`](docs/DEPLOY.md) |
| 20 | Setup README covering: key generation, EB app registration (sandbox + production), YNAB PAT, DB bootstrap, first auth, first sync, first push | [§3 Prerequisites](#3-prerequisites), [§6 Production EB](#6-local-development-against-the-production-eb-app), [§12 Troubleshooting](#12-troubleshooting) |

Acceptance gates re-verified against `master` at v1 release:

- [x] sync → review → push end-to-end works against Mock ASPSP ([§5](#5-sandbox-first-run-mock-aspsp))
- [x] `php artisan test` green
- [x] `vendor/bin/phpstan analyse` level 8 green
- [x] `vendor/bin/pint --test` clean
- [x] `docker compose -f docker-compose.prod.yml build` succeeds

SPEC §14.1 v1 non-goals (web UI, multi-user, pending/PDNG, transfer-pair correlation, CSV import, IBKR, LLM categorisation, scheduled sync, etc.) remain explicitly deferred.
