COMPOSE := docker compose -f docker-compose.prod.yml

.PHONY: help build deploy migrate cache logs ps shell verify

help:
	@echo "Spendula prod targets (run on the prod host):"
	@echo "  make build    Rebuild the app image."
	@echo "  make deploy   Pull, build app, force-recreate app container, migrate, cache config + routes."
	@echo "  make migrate  Run pending migrations only."
	@echo "  make cache    Re-cache config + routes only."
	@echo "  make logs     Tail app + web logs."
	@echo "  make ps       Show container status."
	@echo "  make shell    Open a shell inside the app container."
	@echo "  make verify   Sanity check: list enabled counterparty rules inside the app container."

build:
	$(COMPOSE) build app

deploy:
	git pull
	$(COMPOSE) build app
	$(COMPOSE) up -d --force-recreate --no-deps app
	$(COMPOSE) exec app php artisan migrate --force
	$(COMPOSE) exec app php artisan config:cache
	$(COMPOSE) exec app php artisan route:cache

migrate:
	$(COMPOSE) exec app php artisan migrate --force

cache:
	$(COMPOSE) exec app php artisan config:cache
	$(COMPOSE) exec app php artisan route:cache

logs:
	$(COMPOSE) logs -f --tail=200 app web

ps:
	$(COMPOSE) ps

shell:
	$(COMPOSE) exec app bash

verify:
	$(COMPOSE) exec app ls config/counterparty-rules-enabled/
