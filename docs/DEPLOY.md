# Spendula — Deployment run book

Production runs as a three-container Docker Compose stack on the operator's server, fronted by the host's existing reverse proxy. Local dev is bare metal on macOS (see `README.md` for that). This document covers production only.

## Topology

```
                            Private network
                          (tailnet / VPN / LAN)
   spendula.example.com  ───────────────────────┐
                                                 │
                                       ┌─────────▼──────────┐
                                       │  host reverse      │
                                       │  proxy (Caddy,     │
                                       │  nginx, Traefik…)  │
                                       │  pre-existing      │
                                       └─────────┬──────────┘
                                                 │
                                  127.0.0.1:8765 │  (loopback only)
                                                 │
                               ┌─────────────────▼─────────────────┐
                               │  docker compose stack             │
                               │                                   │
                               │    web (nginx:alpine) ─┐          │
                               │                        │ FastCGI  │
                               │    app (php-fpm)  ◄────┘          │
                               │      │                            │
                               │      ▼                            │
                               │    db (postgres:18-alpine)        │
                               │                                   │
                               └───────────────────────────────────┘
```

Only `web` publishes a port; both `app` and `db` are compose-internal only. The `web` port is bound to `127.0.0.1:8765`, so nothing on the LAN or public internet can reach the raw stack — the reverse proxy is the sole entry point.

## Host prerequisites

- Docker Engine + compose plugin installed.
- A reverse proxy (e.g. Caddy, nginx, Traefik) already running on the host, with TLS termination configured (Let's Encrypt or equivalent), serving on the operator's hostname of choice.
- Outbound network from the host to `api.enablebanking.com`, `api.ynab.com`, and the chosen exchange-rate provider.

## First-time setup

```bash
# 1. Clone the repo onto the host.
git clone <repo-url> /srv/spendula
cd /srv/spendula

# 2. Drop the Enable Banking private key next to the repo.
# (Match the filename in the compose bind mount.)
cp /wherever/you/kept/it.key ./private.key
chmod 600 ./private.key

# 3. Create .env from the template, fill in real secrets.
cp .env.example .env
chmod 600 .env
$EDITOR .env
# At minimum set:
#   APP_ENV=production
#   APP_DEBUG=false
#   APP_URL=https://spendula.example.com    # your actual hostname
#   DB_HOST=db
#   DB_DATABASE=spendula
#   DB_USERNAME=spendula
#   DB_PASSWORD=<strong-password>
#   SPENDULA_ENABLE_BANKING_APP_ID=...
#   SPENDULA_YNAB_ACCESS_TOKEN=...
#   SPENDULA_YNAB_PLAN_ID=...
#   SPENDULA_CALLBACK_URL=https://spendula.example.com/banking/callback

# 4. Build the app image.
docker compose -f docker-compose.prod.yml build

# 5. Bring the db up first so it initialises with the right user/password,
#    then the rest.
docker compose -f docker-compose.prod.yml up -d db
docker compose -f docker-compose.prod.yml up -d

# 6. Run initial migrations and cache the config.
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache

# 7. Wire up the host reverse proxy (see "Reverse-proxy snippet" below).
```

Then run the initial bank link (`spendula:banks:sync` → `spendula:auth:start mock` → open URL in browser) to verify the round-trip.

## Reverse-proxy snippet (Caddy example)

The snippet below is for Caddy; adapt for nginx/Traefik/etc. as needed. Add it to your existing reverse-proxy configuration (not to anything in this repo):

```caddyfile
spendula.example.com {
    reverse_proxy 127.0.0.1:8765

    # TLS — handled by the reverse proxy's own certificate management
    # (Let's Encrypt, ACME, or whatever your stack uses). Nothing
    # Spendula-specific is needed.

    # Callback rate limit (SPEC §9.2): 10 req/min per source IP.
    # Requires Caddy's rate_limit module; if you don't have it, drop this block.
    @callback path /banking/callback
    rate_limit @callback {
        zone banking_callback {
            key    {remote_host}
            events 10
            window 1m
        }
    }
}
```

Reload the reverse proxy (`sudo systemctl reload caddy`, `nginx -s reload`, etc.) after editing.

## Deploy process (subsequent updates)

```bash
cd /srv/spendula
make deploy
```

`make deploy` runs `git pull`, `build app`, `up -d --force-recreate --no-deps app`, `migrate --force`, `config:cache`, `route:cache` in order. `make build` is the build-only variant. `make help` lists everything (`migrate`, `cache`, `logs`, `ps`, `shell`, `verify`).

The raw sequence the Makefile expands to:

```bash
git pull
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml up -d --force-recreate --no-deps app
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
```

**`--force-recreate --no-deps app` is load-bearing.** A bare `up -d` after a `build app` sometimes leaves the previous container in place if compose decides the image hash didn't change in a way it cares about — the result is a freshly-built image that is never actually scheduled, and the running app keeps serving stale code. Real bug hit 2026-05-11: a counterparty-rule fix had been merged and built, but `up -d` skipped the recreate, so the bug kept reproducing in prod. `--force-recreate --no-deps app` recreates the app container unconditionally; `--no-deps` keeps `db` and `web` untouched (no point bouncing them on an app-only change).

If you suspect staleness after a deploy, `make verify` lists the enabled counterparty rules inside the app container — a quick way to confirm the new code/config landed.

No rolling deploys, no zero-downtime requirements — single-user tool, brief downtime during migration is fine.

## Running artisan commands

```bash
cd /srv/spendula
docker compose -f docker-compose.prod.yml exec app php artisan spendula:sync
docker compose -f docker-compose.prod.yml exec app php artisan spendula:review
docker compose -f docker-compose.prod.yml exec app php artisan spendula:push
```

A tiny shell alias makes the weekly ritual ergonomic:

```bash
# ~/.zshrc or /etc/profile.d/spendula.sh on the host
spendula() {
    docker compose -f /srv/spendula/docker-compose.prod.yml exec app php artisan "spendula:$@"
}

# Then:
#   spendula sync
#   spendula review
#   spendula push
#   spendula status
```

## Why port 8765 on loopback?

Two reasons:

1. **The reverse proxy is the sole reachability point.** Publishing on `127.0.0.1` keeps the raw nginx/fpm stack off the LAN and off the public internet. Nothing can bypass the proxy's rate limits or TLS by hitting the compose stack directly.
2. **Port 8765 specifically** is just an uncommon port that doesn't conflict with other services. Change it in `docker-compose.prod.yml` and the reverse-proxy config together if you have a collision.

## Backups

The `db-data` named volume holds all Spendula state; backing it up is enough to restore.

```bash
# One-off pg_dump to host storage (adjust path to your backup regime).
docker compose -f docker-compose.prod.yml exec -T db \
    pg_dump -U spendula -d spendula > /var/backups/spendula-$(date +%F).sql
```

The host's existing backup regime (which covers `/srv/spendula`) should also pick up `.env` and `private.key`. Those two files plus the DB dump are everything you need to restore.

## Troubleshooting

- **`app` container restarts in a loop** — check `docker compose logs app`. Usually one of: missing `.env`, missing `private.key`, wrong `DB_HOST` (must be `db`, not `127.0.0.1`), or a missing migration.
- **Callback 502s** — `web` can reach `app:9000` inside the compose network but nginx's `fastcgi_pass` might have a slow DNS lookup on cold start. Check `docker compose logs web`.
- **Reverse proxy can't reach the stack** — confirm the `web` container is binding `127.0.0.1:8765:80` (`docker compose ps`); the reverse proxy and the stack must be on the same host.
- **`migrate --force` fails with permission errors** — first-time, the `db` container needs to finish initialising before the app can connect. The healthcheck should gate this, but if it raced, just run `docker compose up -d` once more.
