# Spendula

A self-hosted, single-user pipe between European banks and YNAB. Enable Banking (PSD2) pulls transactions in; a terminal review step gates what reaches YNAB; `spendula:push` sends approved transactions onward. YNAB remains the system of record for budgets, categories, and reporting — Spendula owns only ingestion, dedup, and approval state.

## Documentation

- [`docs/SPEC.md`](docs/SPEC.md) — authoritative product spec.
- [`docs/PLAN.md`](docs/PLAN.md) — phased implementation plan (phase 1 → phase 4).
- [`docs/DEPLOY.md`](docs/DEPLOY.md) — production deployment run book and host Caddy template.
- [`CLAUDE.md`](CLAUDE.md) — session orientation for future Claude Code runs in this repo.
- `spike/` — original PoC that proved end-to-end feasibility. Reference-only; **do not import or modify**. `spike/FINDINGS.md` has gotchas worth reading before touching Enable Banking or YNAB code.

## Stack

- Laravel 13 on PHP 8.4
- PostgreSQL 18
- PHPUnit, PHPStan level 8, Laravel Pint
- Nginx in prod only (`nginx:alpine`), FastCGI → php-fpm
- No queue, no scheduler, no SPA, no build pipeline. Artisan is the v1 interface.

## Local development

Bare metal on macOS. No containers.

```bash
# 1. Install PHP 8.4 and PostgreSQL 18 via Homebrew.
brew install php@8.4 postgresql@18
brew services start postgresql@18

# 2. Create the dev database.
createdb spendula_dev

# 3. Clone and install dependencies.
git clone <repo-url> spendula
cd spendula
composer install

# 4. Configure .env (sandbox EB credentials + YNAB PAT).
cp .env.example .env
$EDITOR .env
php artisan key:generate

# 5. Place the Enable Banking private key.
#    Path is configurable via SPENDULA_ENABLE_BANKING_PRIVATE_KEY_PATH.
mkdir -p storage/keys
cp /path/to/your/enablebanking.key storage/keys/enablebanking.key
chmod 600 storage/keys/enablebanking.key

# 6. Run migrations and tests.
php artisan migrate
php artisan test
```

The one HTTP route (the EB OAuth callback) runs via `php artisan serve`:

```bash
php artisan serve
# → http://localhost:8000/banking/callback is reachable
```

The EB **sandbox** app is already configured with `http://localhost:8000/banking/callback` as an allowed redirect URL.

### Artisan commands

Phase-1 (implemented):

| Command | Purpose |
|---|---|
| `spendula:banks:sync` | Reconcile `banks` with `config/spendula-banks.php`. |
| `spendula:auth:start {bank_slug}` | Start an EB consent flow; prints the URL to open. |
| `spendula:accounts:seed-mock` | One-off phase-1 mapper: wire a bank account to a YNAB account. |
| `spendula:sync [--bank=slug]` | Pull new transactions from Enable Banking. |
| `spendula:review [--bulk-approve-trivial]` | Terminal queue: Approve / Skip / Transfer. |
| `spendula:push` | Send approved transactions to YNAB. |

Phase-2+ stubs (ship as "not yet implemented"):

| Command | Phase |
|---|---|
| `spendula:accounts:map` | 2 (interactive mapping for real banks) |
| `spendula:tracking:snapshot [--account=id]` | 3 (foreign-currency balance snapshots) |
| `spendula:status` | 4 (dashboard) |
| `spendula:convert-pending` | 4 (retry failed FX conversions) |

See `docs/PLAN.md` for phase-by-phase acceptance criteria.

### Phase-1 end-to-end walkthrough

Against Mock ASPSP in the EB sandbox:

```bash
# 1. Seed the banks catalogue (mock only in phase 1).
php artisan spendula:banks:sync

# 2. Start EB consent flow. Opens a URL; complete it in a browser.
#    Make sure `php artisan serve` is running on :8000 so the callback lands.
php artisan spendula:auth:start mock

# 3. After the callback success page, map the discovered account(s) to YNAB.
#    Get ynab_account_id from `https://api.ynab.com/v1/plans/{plan_id}/accounts`.
php artisan spendula:accounts:seed-mock \
    --bank-account-id=<uuid-from-callback-page> \
    --ynab-account-id=<uuid-from-ynab> \
    --display-name="Main checking" \
    --import-cutoff-date=2026-01-01

# 4. Sync transactions.
php artisan spendula:sync

# 5. Review them in the terminal. a=approve, s=skip, t=transfer, d=details, q=quit.
php artisan spendula:review

# 6. Push approved ones to YNAB.
php artisan spendula:push
```

Mock ASPSP ships with zero seeded accounts — create at least one at
<https://enablebanking.com/cp/mock-aspsp> before step 2, or the consent flow
will silently error (see `spike/FINDINGS.md` #1).

## Production

Three-container Docker Compose stack (`app`, `web`, `db`) behind the host's existing Caddy instance, reverse-proxied from `spendula.example.com` → `127.0.0.1:8765`.

See [`docs/DEPLOY.md`](docs/DEPLOY.md) for the deploy run book, the host Caddy snippet template, and the backup recipe.

## Conventions

- **Read `docs/SPEC.md` and `docs/PLAN.md` before implementing a feature.** SPEC is the source of truth for behaviour; PLAN slices it into acceptance-checkable phases.
- **`spike/` is reference only.** Don't import, modify, or depend on it. Copy patterns by hand where useful (especially the JWT signer in `spike/lib.php`).
- **Secrets never committed.** `.env` is gitignored; `.env.example` is the schema reference. EB private key lives at `storage/keys/enablebanking.key` (also gitignored).
- **Callback path is `/banking/callback`** — matches the registered URLs in the EB sandbox app.
- **Money math uses `bcmath` or integers, never floats.** See SPEC §11.
- **YNAB paths use `/plans/{plan_id}/…`**, never the deprecated `/budgets/{budget_id}/…`.

## Project status

- Phase 0 — scaffolding: **done**.
- Phase 1 — Mock ASPSP end-to-end (sync → review → push): **done**. All
  SPEC §4 tables migrated; EB and YNAB clients with typed error surfaces;
  match-update-or-insert with occurrence disambiguation; review CLI with
  raw-mode TTY and bulk-approve-trivial; push with retry-safe
  `duplicate_import_ids` handling.
- Phase 2 — real banks behind the production EB app: **pending**. Gated on
  EB production app approval (wall-time, outside Claude's control). See
  `docs/PLAN.md` §2.
- Phase 3 — tracking accounts + multi-currency: **pending**.
- Phase 4 — `spendula:status`, `convert-pending`, docs polish: **pending**.
