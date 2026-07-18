# POS — runner surface. `make help` lists everything.
COMPOSE_DEV  := docker compose -f compose.dev.yml
COMPOSE_PROD := docker compose -f compose.prod.yml

.DEFAULT_GOAL := help
.PHONY: help dev dev-down logs ps seed migrate dev-key test test-backend test-web test-bo typecheck clean build prod-up prod-down prod-logs

help: ## List available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

dev: ## Bring up the full dev stack (db, api, register, back office)
	$(COMPOSE_DEV) up -d --build
	@echo "api http://localhost:8000  register http://localhost:5174  back office http://localhost:5175"

dev-down: ## Stop the dev stack (volumes survive)
	$(COMPOSE_DEV) down

logs: ## Tail dev stack logs
	$(COMPOSE_DEV) logs -f --tail=100

ps: ## Dev stack status
	$(COMPOSE_DEV) ps

# Mints from the api dev image compose itself builds (pos-dev-api), NOT the
# pos-api:dev tag from Task 1's raw `docker build` — that image never gets
# app code, so `artisan` isn't in it. The dev stage's own USER is `pos`
# (backend/Dockerfile), which is uid 1000 — matches the host user by
# construction, so files this writes under the bind mount are never root's.
# See .env.example for the full explanation.
dev-key: ## Mint an APP_KEY for the root .env
	$(COMPOSE_DEV) build api
	docker run --rm -v "$(CURDIR)/backend:/app" pos-dev-api php artisan key:generate --show

# compose.dev.yml's api/web/back-office services start as root (to chown a
# fresh named volume once) then hand off to a non-root user for good — pos
# for api, node for web/back-office (see compose.dev.yml comments). `exec`
# without --user reconnects as root, so every target below that writes to a
# bind-mounted directory names the user explicitly.
seed: ## Fresh migrate + seed (prints dev PINs and device tokens)
	$(COMPOSE_DEV) exec --user pos api php artisan migrate:fresh --seed

migrate: ## Run pending migrations
	$(COMPOSE_DEV) exec --user pos api php artisan migrate

test: test-backend test-web test-bo ## All suites, in containers

# -e overrides beat phpunit.xml <env> values by design (real env wins — the
# same mechanism as the local DB_PORT=5433 habit). Never edit phpunit.xml.
test-backend: ## Pest against the compose db (creates pos_test if missing)
	$(COMPOSE_DEV) exec db psql -U pos -d pos -tc "SELECT 1 FROM pg_database WHERE datname='pos_test'" | grep -q 1 || $(COMPOSE_DEV) exec db createdb -U pos pos_test
	$(COMPOSE_DEV) exec --user pos -e DB_HOST=db -e DB_PORT=5432 -e DB_DATABASE=pos_test api php -d memory_limit=512M ./vendor/bin/pest

test-web: ## Register app vitest
	$(COMPOSE_DEV) exec --user node web npm test

test-bo: ## Back-office vitest
	$(COMPOSE_DEV) exec --user node back-office npm test

typecheck: ## tsgo on both frontend apps
	$(COMPOSE_DEV) exec --user node web npm run typecheck
	$(COMPOSE_DEV) exec --user node back-office npm run typecheck

clean: ## Dev stack down AND volumes destroyed (asks first)
	@read -p "Destroy dev volumes (db data, vendor, node_modules)? [y/N] " a && [ "$$a" = "y" ]
	$(COMPOSE_DEV) down -v

build: ## Build all three production images
	docker build --target prod -t pos-api:latest backend
	docker build --target runner -t pos-web:latest frontend/web
	docker build --target runner -t pos-back-office:latest frontend/back-office

prod-up: ## Start the production stack (needs .env — see .env.prod.example)
	$(COMPOSE_PROD) up -d --build

prod-down: ## Stop the production stack
	$(COMPOSE_PROD) down

prod-logs: ## Tail production logs
	$(COMPOSE_PROD) logs -f --tail=100
