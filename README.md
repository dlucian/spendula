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

Phase-1 real (to be implemented):

| Command | Purpose |
|---|---|
| `spendula:banks:sync` | Reconcile `banks` with `config/spendula-banks.php`. |
| `spendula:auth:start {bank_slug}` | Start an EB consent flow; prints the URL to open. |
| `spendula:accounts:seed-mock` | One-off phase-1 mapper: wire a bank account to a YNAB account. |
| `spendula:sync [--bank=slug]` | Pull new transactions from Enable Banking. |
| `spendula:review` | Terminal queue: Approve / Skip / Transfer. |
| `spendula:push` | Send approved transactions to YNAB. |

Phase-2+ stubs (ship as "not yet implemented"):

| Command | Phase |
|---|---|
| `spendula:accounts:map` | 2 (interactive mapping for real banks) |
| `spendula:tracking:snapshot [--account=id]` | 3 (foreign-currency balance snapshots) |
| `spendula:status` | 4 (dashboard) |
| `spendula:convert-pending` | 4 (retry failed FX conversions) |

All commands are currently stubs. See `docs/PLAN.md` for phase-by-phase acceptance criteria.

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

Phase 0 (scaffolding) is complete: Laravel 13 + PG 18 bootstrap, artisan command surface as stubs, production Docker build verified. Phase 1 (minimum viable pipe against Mock ASPSP) is next.
