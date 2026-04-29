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

### Local development against the **production** EB app

The sandbox EB app accepts `http://localhost:8000/banking/callback`. The
production app only accepts `https://…` redirect URLs, so working with real
bank consents from your local machine needs HTTPS termination in front of
`php artisan serve`. Recipe (assumes a Tailscale-joined macOS dev box;
substitute your own tailnet hostname for `prod.spendula.example`):

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

# 2. /etc/hosts pin so the tailnet hostname resolves locally to 127.0.0.1.
#    (Other tailnet peers still resolve to the real Tailscale IP.)
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

### Artisan commands

Phase-1 (implemented):

| Command | Purpose |
|---|---|
| `spendula:banks:sync` | Reconcile `banks` with `config/spendula-banks.php`. |
| `spendula:banks:add` | Add an operator-only bank that should never appear in source (production targets, etc.). |
| `spendula:auth:start {bank_slug}` | Start an EB consent flow; prints the URL to open. |
| `spendula:accounts:map` | Interactive YNAB-account mapper; walks unmapped rows or maps a single one with `--bank-account-id` + `--ynab-account-id`. |
| `spendula:accounts:seed-mock` | Scripted single-row mapper (still useful for tests / CI). |
| `spendula:sync [--bank=slug]` | Pull new transactions from Enable Banking. |
| `spendula:review [--bulk-approve-trivial]` | Terminal queue: Approve / Skip / Transfer. |
| `spendula:push` | Send approved transactions to YNAB. |

Phase-2+ stubs (ship as "not yet implemented"):

| Command | Phase |
|---|---|
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
