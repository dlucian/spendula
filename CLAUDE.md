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

## Phase 1 scope

Mock ASPSP only. One bank, one YNAB account, end-to-end via three real artisan commands (`spendula:sync`, `spendula:review`, `spendula:push`) plus `banks:sync`, `auth:start`, and the `/banking/callback` route. Other commands (`accounts:map`, `status`, `convert-pending`, `tracking:snapshot`) ship as stubs so the command surface is stable. See `docs/PLAN.md` for phase 2+.

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

## Sandbox redirect URLs (already registered in the EB sandbox app)

- `http://localhost:8000/banking/callback` — used by `php artisan serve` in local dev
- `https://localhost/banking/callback`
- `https://spendula.ddev.site/banking/callback` — DDEV fallback, not currently used

Production app (phase 2) will register the Tailscale URL; see SPEC §9.5.

## When in doubt

1. Check `docs/SPEC.md`.
2. Check `spike/FINDINGS.md`.
3. Ask the operator.
