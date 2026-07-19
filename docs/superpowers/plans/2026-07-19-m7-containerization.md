# M7 — Containerization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `make dev` on a clean machine with only Docker yields the full working stack; `make prod-up` serves both apps over TLS behind one FrankenPHP entrypoint; `make restore-drill` proves a backup restores.

**Architecture:** Three multi-stage Dockerfiles (FrankenPHP API; two standalone-output Next runners), two root compose files (dev with bind mounts + hot reload; prod with host-routed automatic TLS), one self-documenting root Makefile, one added CI job building the prod images. Spec: `docs/superpowers/specs/2026-07-19-m7-containerization-design.md`.

**Tech Stack:** Docker + Compose v2, FrankenPHP (PHP 8.5), node:24-alpine, postgres:18-alpine, GNU Make.

## Global Constraints

- Branch `m7-containerization` (already checked out, atop the `fix-ci` branch — the TS arrangement there is REQUIRED for the frontend images). Commits `M7: <what>`, NO co-author trailer.
- **Machine-local:** host Postgres port 5433 is taken by the existing infra container and 5173 by another project — the dev compose must not hard-bind 5432/5433: `POS_DEV_DB_PORT` env with default 5432, and on THIS machine `.env` sets 5434 for verification runs. Ports 8000/5174/5175 are what the native servers use — STOP the native dev servers before compose smoke tests (`pkill -f "artisan serve"` is NOT safe — check with `ss -ltnp | grep -E ':(8000|5174|5175)'` and report if occupied rather than killing blindly).
- Never bake secrets or env into images: config caching happens at container BOOT (entrypoint), not build. `.env` files never in build contexts (`.dockerignore`).
- Images run as non-root. Healthchecks on api (`/api/v1/health`) and db (`pg_isready`).
- Native dev workflow (artisan serve + npm run dev + a Postgres) must keep working — this milestone adds paths, never removes one.
- Verify the exact FrankenPHP image tag before writing it (`docker pull` candidates: `dunglas/frankenphp:php8.5`, `dunglas/frankenphp:1-php8.5`); pin what actually exists, note it in the report.
- All verification commands that need Docker run on this machine's daemon — real builds, real boots. Suites: backend `cd backend && DB_PORT=5433 php -d memory_limit=512M ./vendor/bin/pest` (462 baseline) only if backend code changes (entrypoint script is not backend code); frontend gates `npm run typecheck && npm test && npm run build` per app (80/80 baselines) after the next.config edits.

---

### Task 1: Backend image — FrankenPHP Dockerfile, entrypoint, Caddyfiles

**Files:**
- Create: `backend/Dockerfile`, `backend/.dockerignore`, `backend/docker/entrypoint.sh`, `backend/docker/Caddyfile.dev`, `backend/docker/Caddyfile.prod`
- Test: real builds + a run smoke (steps below)

**Interfaces:**
- Produces: image targets `dev` and `prod`; entrypoint honoring `MIGRATE_ON_BOOT=1`; dev Caddyfile serving plain HTTP :8000; prod Caddyfile terminating TLS for `{$POS_REGISTER_DOMAIN}` + `{$POS_ADMIN_DOMAIN}` and host-routing to `web:3000` / `back-office:3000`, with `/api/*` (and the api's own routes) served by PHP on both hosts. Compose (Tasks 3/5) consumes: service listens on 8000 (dev) / 80+443 (prod).

- [ ] **Step 1: `.dockerignore`**

```
# backend/.dockerignore
.env
.env.*
vendor
node_modules
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
tests
.phpunit.result.cache
```

(Keep `tests` out of prod images; the dev target bind-mounts the whole tree anyway.)

- [ ] **Step 2: The Dockerfile**

```dockerfile
# backend/Dockerfile
# syntax=docker/dockerfile:1

# ---- base: FrankenPHP + the extensions this app needs -----------------------
# Verify the exact tag exists before building (docker pull); pin what does.
FROM dunglas/frankenphp:php8.5 AS base

RUN install-php-extensions pdo_pgsql pgsql bcmath intl opcache

# Non-root: FrankenPHP needs cap_net_bind_service to bind 80/443 as non-root.
RUN useradd -u 1000 -m pos \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R pos:pos /data/caddy /config/caddy

WORKDIR /app

# ---- vendor: composer layer cached off the lockfile alone -------------------
FROM base AS vendor
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer \
    composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

# ---- dev: full deps, code arrives via bind mount ----------------------------
FROM base AS dev
ENV APP_ENV=local
COPY docker/Caddyfile.dev /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
USER pos
ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---- prod -------------------------------------------------------------------
FROM base AS prod
ENV APP_ENV=production
COPY --chown=pos:pos . .
COPY --from=vendor --chown=pos:pos /app/vendor ./vendor
RUN composer dump-autoload --optimize --no-dev \
    && chown -R pos:pos storage bootstrap/cache
COPY docker/Caddyfile.prod /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'opcache.validate_timestamps=0\nopcache.memory_consumption=192\n' \
       > "$PHP_INI_DIR/conf.d/zz-opcache.ini"
USER pos
HEALTHCHECK --interval=15s --timeout=3s --retries=5 \
  CMD curl -fsS http://127.0.0.1:8000/api/v1/health || exit 1
ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
```

> If the base image lacks `curl` or `useradd` (alpine variants use `adduser`), adapt to
> what the pinned image actually provides and note it. The healthcheck targets :8000
> because the prod Caddyfile also listens there internally for health (below) — TLS
> ports carry the public traffic.

- [ ] **Step 3: The entrypoint**

```bash
#!/bin/sh
# backend/docker/entrypoint.sh
# Config is cached at BOOT, never at build: the image carries no environment.
set -eu

if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
fi

if [ "${MIGRATE_ON_BOOT:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec "$@"
```

- [ ] **Step 4: The Caddyfiles**

```caddyfile
# backend/docker/Caddyfile.dev — plain HTTP for the dev compose network
{
    frankenphp
    auto_https off
}

:8000 {
    root * /app/public
    encode zstd gzip
    php_server
}
```

```caddyfile
# backend/docker/Caddyfile.prod — one public entrypoint: TLS + host routing.
# POS_REGISTER_DOMAIN / POS_ADMIN_DOMAIN come from the prod .env via compose.
# POS_TLS_ISSUER lets a domainless smoke test use Caddy's internal CA:
#   POS_TLS_ISSUER=internal  →  self-signed, no ACME attempts.
{
    frankenphp
}

# Internal health listener (container healthcheck + no public exposure needed)
:8000 {
    root * /app/public
    php_server
}

{$POS_REGISTER_DOMAIN} {
    tls {
        issuer {$POS_TLS_ISSUER:acme}
    }
    encode zstd gzip
    @api path /api/*
    handle @api {
        root * /app/public
        php_server
    }
    handle {
        reverse_proxy web:3000
    }
}

{$POS_ADMIN_DOMAIN} {
    tls {
        issuer {$POS_TLS_ISSUER:acme}
    }
    encode zstd gzip
    @api path /api/*
    handle @api {
        root * /app/public
        php_server
    }
    handle {
        reverse_proxy back-office:3000
    }
}
```

> Verify Caddyfile syntax details against the pinned FrankenPHP version
> (`frankenphp validate --config`): the `issuer` sub-directive form and env-default
> syntax `{$VAR:default}` must be accepted; if `issuer internal` needs the
> `tls internal` shorthand instead, use two vhost blocks via an env-selected import or
> document `POS_TLS_ISSUER` handling accordingly — validated, not assumed. Note: with
> the api serving `/api/*` directly on both hosts, the Next apps' own `/api` rewrite
> never fires in prod (Caddy routes first) — the rewrite remains the dev-mode path.

- [ ] **Step 5: Build + smoke both targets**

Run (from repo root):
`docker build --target prod -t pos-api:prod backend/` and `docker build --target dev -t pos-api:dev backend/`
Expected: both build. Then smoke the prod image against the EXISTING infra Postgres (network trick — simplest is a throwaway compose in the scratchpad or `--network host` with `DB_PORT=5433`):
`docker run --rm --network host -e APP_ENV=production -e APP_KEY=base64:$(openssl rand -base64 32) -e DB_HOST=127.0.0.1 -e DB_PORT=5433 -e DB_DATABASE=pos -e DB_USERNAME=pos -e DB_PASSWORD=<from infra/.env> -e POS_CURRENCY=USD -e POS_BUSINESS_NAME=Smoke pos-api:prod &` then `curl -fsS localhost:8000/api/v1/health` → healthy JSON; stop the container.
(If `--network host` misbehaves with the pinned image, use a scratch compose with a db service instead — the point is a real HTTP 200 from `/api/v1/health` out of the prod image.)

- [ ] **Step 6: Commit**

```bash
git add backend/Dockerfile backend/.dockerignore backend/docker && git commit -m "M7: backend image — FrankenPHP multi-stage, boot-time config, host-routing Caddyfile"
```

---

### Task 2: Frontend images — standalone Next runners ×2, env-driven rewrites

**Files:**
- Modify: `frontend/web/next.config.ts`, `frontend/back-office/next.config.ts`
- Create: `frontend/web/Dockerfile`, `frontend/web/.dockerignore`, `frontend/back-office/Dockerfile`, `frontend/back-office/.dockerignore`
- Test: both apps' full gates + real image builds + run smoke

**Interfaces:**
- Produces: `pos-web` / `pos-back-office` images, `runner` target listening on `PORT` (default 3000), `dev` target running `npm run dev`; both next.configs honor `API_ORIGIN` (default `http://127.0.0.1:8000`) and emit `output: 'standalone'`.

- [ ] **Step 1: next.config edits (both apps, identical shape)**

```ts
const nextConfig: NextConfig = {
  output: 'standalone',
  // (existing typescript block + comment stays)
  typescript: { ignoreBuildErrors: true },
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        // Native dev talks to artisan/FrankenPHP on localhost; containers set
        // API_ORIGIN=http://api:8000. In prod the edge Caddy routes /api before
        // Next ever sees it — this rewrite is the dev-mode path.
        destination: `${process.env.API_ORIGIN ?? 'http://127.0.0.1:8000'}/api/:path*`,
      },
    ]
  },
}
```

- [ ] **Step 2: Run both apps' gates** — `npm run typecheck && npm test && npm run build` in each; PASS (80/80 baselines). The build now also emits `.next/standalone`.

- [ ] **Step 3: Dockerfile (write once, copy to both apps — they are intentionally identical)**

```dockerfile
# frontend/web/Dockerfile  (and frontend/back-office/Dockerfile, identical)
# syntax=docker/dockerfile:1

FROM node:24-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

FROM node:24-alpine AS build
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build

FROM node:24-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 HOSTNAME=0.0.0.0 PORT=3000
RUN addgroup -S pos && adduser -S pos -G pos
COPY --from=build --chown=pos:pos /app/.next/standalone ./
COPY --from=build --chown=pos:pos /app/.next/static ./.next/static
COPY --from=build --chown=pos:pos /app/public ./public
USER pos
EXPOSE 3000
CMD ["node", "server.js"]

FROM node:24-alpine AS dev
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
RUN addgroup -S pos && adduser -S pos -G pos && chown pos:pos /app
USER pos
# Source arrives via bind mount; node_modules via named volume (installed by the
# container on first boot — see compose command).
CMD ["sh", "-c", "npm install && npm run dev"]
```

`.dockerignore` (both): `node_modules`, `.next`, `*.tsbuildinfo`.

- [ ] **Step 4: Build + smoke** — `docker build --target runner -t pos-web:prod frontend/web/` (and back-office). `docker run --rm -p 3001:3000 pos-web:prod` → `curl -fsS localhost:3001` returns the app shell HTML. Stop.

- [ ] **Step 5: Commit**

```bash
git add frontend/web/next.config.ts frontend/web/Dockerfile frontend/web/.dockerignore frontend/back-office/next.config.ts frontend/back-office/Dockerfile frontend/back-office/.dockerignore && git commit -m "M7: frontend images — standalone Next runners, env-driven API origin"
```

---

### Task 3: `compose.dev.yml` + infra retirement

**Files:**
- Create: `compose.dev.yml` (repo root), `.env.example` (repo root — compose-level vars)
- Modify: `infra/docker-compose.yml` (replace body with a pointer comment), `.gitignore` (root `.env`, `backups/`)
- Test: real boot on this machine (with `POS_DEV_DB_PORT=5434` in an uncommitted root `.env`)

**Interfaces:**
- Produces: services `db`, `api` (:8000), `web` (:5174), `back-office` (:5175) — names the Makefile (Task 4) and prod compose (Task 5) rely on.

- [ ] **Step 1: The compose file**

```yaml
# compose.dev.yml — the full dev stack: `make dev`.
# Native dev (artisan serve + npm run dev + any Postgres) remains fully supported.
name: pos-dev

services:
  db:
    image: postgres:18-alpine
    environment:
      POSTGRES_DB: pos
      POSTGRES_USER: pos
      POSTGRES_PASSWORD: ${POS_DEV_DB_PASSWORD:-pos}
    ports:
      - "${POS_DEV_DB_PORT:-5432}:5432"
    volumes:
      - pgdata:/var/lib/postgresql
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U pos -d pos"]
      interval: 5s
      timeout: 5s
      retries: 10

  api:
    build: { context: backend, target: dev }
    depends_on:
      db: { condition: service_healthy }
    environment:
      APP_ENV: local
      APP_KEY: ${POS_DEV_APP_KEY:?run 'make dev-key' once and put it in .env}
      APP_DEBUG: "true"
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: pos
      DB_USERNAME: pos
      DB_PASSWORD: ${POS_DEV_DB_PASSWORD:-pos}
      POS_CURRENCY: USD
      POS_BUSINESS_NAME: "Dev Trading Co"
    ports:
      - "8000:8000"
    volumes:
      - ./backend:/app
      - api_vendor:/app/vendor
    # Bind mount hides the image's vendor; install on boot if absent, then serve.
    command: ["sh", "-c", "composer install --no-interaction && exec entrypoint.sh frankenphp run --config /etc/frankenphp/Caddyfile"]

  web:
    build: { context: frontend/web, target: dev }
    environment:
      API_ORIGIN: http://api:8000
      PORT: 5174
    ports:
      - "5174:5174"
    volumes:
      - ./frontend/web:/app
      - web_node_modules:/app/node_modules
    command: ["sh", "-c", "npm install && npm run dev"]

  back-office:
    build: { context: frontend/back-office, target: dev }
    environment:
      API_ORIGIN: http://api:8000
      PORT: 5175
    ports:
      - "5175:5175"
    volumes:
      - ./frontend/back-office:/app
      - bo_node_modules:/app/node_modules
    command: ["sh", "-c", "npm install && npm run dev"]

volumes:
  pgdata:
  api_vendor:
  web_node_modules:
  bo_node_modules:
```

> `npm run dev` uses `next dev -p 5174` (already in package.json) — the PORT env is
> belt-and-braces. Verify the dev servers bind 0.0.0.0 inside containers (Next dev
> binds all interfaces by default; if not, add `-H 0.0.0.0` to the dev scripts and
> note it).

- [ ] **Step 2: Root `.env.example`** documenting `POS_DEV_DB_PORT`, `POS_DEV_DB_PASSWORD`, `POS_DEV_APP_KEY` (+ how to mint: `docker run --rm pos-api:dev php artisan key:generate --show`). `.gitignore` gains root `.env` and `backups/`.

- [ ] **Step 3: infra retirement** — `infra/docker-compose.yml` body replaced by a comment pointing at `compose.dev.yml` + `make dev` (keep the file so old muscle memory gets a signpost, not an error). Leave `infra/.env*` untouched (this machine still runs the old container until the user chooses to switch).

- [ ] **Step 4: Boot proof on this machine** — root `.env` (uncommitted): `POS_DEV_DB_PORT=5434`, a generated key. Check ports 8000/5174/5175 are free first (report, don't kill). `docker compose -f compose.dev.yml up -d --build`, wait for healthy, then: `curl -fsS localhost:8000/api/v1/health`; `docker compose -f compose.dev.yml exec api php artisan migrate:fresh --seed` (tokens print); `curl -fsS localhost:5174` and `:5175` return HTML. Then `docker compose -f compose.dev.yml down` (keep volumes).

- [ ] **Step 5: Commit**

```bash
git add compose.dev.yml .env.example .gitignore infra/docker-compose.yml && git commit -m "M7: full docker dev stack — one compose, hot reload, infra compose retired"
```

---

### Task 4: Makefile — core runner surface

**Files:**
- Create: `Makefile` (repo root)
- Test: run each target on this machine against the Task-3 stack

**Interfaces:**
- Produces (consumed by humans + Tasks 5/6/8): `help dev dev-down logs ps seed migrate dev-key test test-backend test-web test-bo typecheck clean`.

- [ ] **Step 1: The Makefile (core)**

```makefile
# POS — runner surface. `make help` lists everything.
COMPOSE_DEV  := docker compose -f compose.dev.yml
COMPOSE_PROD := docker compose -f compose.prod.yml

.DEFAULT_GOAL := help
.PHONY: help dev dev-down logs ps seed migrate dev-key test test-backend test-web test-bo typecheck clean

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

dev-key: ## Mint an APP_KEY for the root .env
	$(COMPOSE_DEV) run --rm --no-deps api php artisan key:generate --show

seed: ## Fresh migrate + seed (prints dev PINs and device tokens)
	$(COMPOSE_DEV) exec api php artisan migrate:fresh --seed

migrate: ## Run pending migrations
	$(COMPOSE_DEV) exec api php artisan migrate

test: test-backend test-web test-bo ## All suites, in containers

test-backend: ## Pest against the compose db (creates pos_test if missing)
	$(COMPOSE_DEV) exec db psql -U pos -d pos -tc "SELECT 1 FROM pg_database WHERE datname='pos_test'" | grep -q 1 || $(COMPOSE_DEV) exec db createdb -U pos pos_test
	$(COMPOSE_DEV) exec -e DB_HOST=db -e DB_PORT=5432 -e DB_DATABASE=pos_test api php -d memory_limit=512M ./vendor/bin/pest

test-web: ## Register app vitest
	$(COMPOSE_DEV) exec web npm test

test-bo: ## Back-office vitest
	$(COMPOSE_DEV) exec back-office npm test

typecheck: ## tsgo on both frontend apps
	$(COMPOSE_DEV) exec web npm run typecheck
	$(COMPOSE_DEV) exec back-office npm run typecheck

clean: ## Dev stack down AND volumes destroyed (asks first)
	@read -p "Destroy dev volumes (db data, vendor, node_modules)? [y/N] " a && [ "$$a" = "y" ]
	$(COMPOSE_DEV) down -v
```

> `test-backend` env overrides beat `phpunit.xml` `<env>` values by design (real env
> wins — the same mechanism as the local `DB_PORT=5433` habit). Never edit
> `phpunit.xml`.

- [ ] **Step 2: Prove each target** on the running Task-3 stack: `make help`, `make dev`, `make seed`, `make test` (all three suites green in containers — expect backend 462, web 80, bo 80), `make typecheck`, `make ps`, `make dev-down`. Report actual counts.

- [ ] **Step 3: Commit**

```bash
git add Makefile && git commit -m "M7: makefile — dev lifecycle, seeding, in-container suites"
```

---

### Task 5: `compose.prod.yml` + `.env.prod.example` + build targets

**Files:**
- Create: `compose.prod.yml`, `.env.prod.example`
- Modify: `Makefile` (add `build prod-up prod-down prod-logs`)
- Test: `docker compose config` validation + a real local boot with `POS_TLS_ISSUER=internal`

**Interfaces:**
- Produces: prod stack `api` (80/443 published, FrankenPHP edge), `web`, `back-office`, `db` (all internal-only); Makefile targets `build`, `prod-up`, `prod-down`, `prod-logs`.

- [ ] **Step 1: The compose file**

```yaml
# compose.prod.yml — single-host production: one FrankenPHP edge, TLS, host routing.
# Requires an .env beside this file — see .env.prod.example. `make prod-up`.
name: pos

services:
  db:
    image: postgres:18-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: pos
      POSTGRES_USER: pos
      POSTGRES_PASSWORD: ${POS_DB_PASSWORD:?}
    volumes:
      - pgdata:/var/lib/postgresql
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U pos -d pos"]
      interval: 10s
      timeout: 5s
      retries: 10

  api:
    build: { context: backend, target: prod }
    restart: unless-stopped
    depends_on:
      db: { condition: service_healthy }
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
      APP_KEY: ${POS_APP_KEY:?}
      APP_URL: https://${POS_REGISTER_DOMAIN:?}
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: pos
      DB_USERNAME: pos
      DB_PASSWORD: ${POS_DB_PASSWORD:?}
      MIGRATE_ON_BOOT: ${POS_MIGRATE_ON_BOOT:-1}
      POS_CURRENCY: ${POS_CURRENCY:?}
      POS_BUSINESS_NAME: ${POS_BUSINESS_NAME:?}
      POS_BUSINESS_ADDRESS: ${POS_BUSINESS_ADDRESS:-}
      POS_BUSINESS_TAX_ID: ${POS_BUSINESS_TAX_ID:-}
      POS_REGISTER_DOMAIN: ${POS_REGISTER_DOMAIN:?}
      POS_ADMIN_DOMAIN: ${POS_ADMIN_DOMAIN:?}
      POS_TLS_ISSUER: ${POS_TLS_ISSUER:-acme}
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"   # HTTP/3
    volumes:
      - caddy_data:/data
      - caddy_config:/config

  web:
    build: { context: frontend/web, target: runner }
    restart: unless-stopped

  back-office:
    build: { context: frontend/back-office, target: runner }
    restart: unless-stopped

volumes:
  pgdata:
  caddy_data:
  caddy_config:
```

- [ ] **Step 2: `.env.prod.example`** — every `:?` var above with one-line comments (mint `POS_APP_KEY` via `make dev-key`; `POS_TLS_ISSUER=internal` for domainless smoke; real domains need DNS → this host and 80/443 reachable).

- [ ] **Step 3: Makefile additions**

```makefile
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
```

- [ ] **Step 4: Validate + local TLS boot.** `docker compose -f compose.prod.yml config -q` with a scratch `.env` (fails loudly listing missing `:?` vars — prove that by omitting one first). Then a REAL local boot: `.env` with `POS_REGISTER_DOMAIN=register.localhost POS_ADMIN_DOMAIN=admin.localhost POS_TLS_ISSUER=internal` (+ key, db password, business vars), ports 80/443 free (check first). `make prod-up`, then `curl -fsSk https://register.localhost/api/v1/health` → healthy; `curl -fsSk https://register.localhost/` → register HTML; `curl -fsSk https://admin.localhost/` → back-office HTML (`*.localhost` resolves to 127.0.0.1 without /etc/hosts edits). `make prod-down`.

- [ ] **Step 5: Commit**

```bash
git add compose.prod.yml .env.prod.example Makefile && git commit -m "M7: production compose — FrankenPHP edge, host-routed TLS, env-guarded config"
```

---

### Task 6: Backups — `backup`, `restore`, `restore-drill`

**Files:**
- Modify: `Makefile`
- Test: real backup + real drill on this machine against seeded dev data

**Interfaces:**
- Produces: `make backup` → `backups/pos-<UTC timestamp>.dump` (pg_dump custom format); `make restore FILE=…` (typed confirm); `make restore-drill` (throwaway container, row-count verification, auto-teardown). All three target the DEV stack's db by default; `COMPOSE=prod` var switches to the prod stack (`make backup COMPOSE=prod`).

- [ ] **Step 1: Makefile additions**

```makefile
COMPOSE_VAR := $(if $(filter prod,$(COMPOSE)),$(COMPOSE_PROD),$(COMPOSE_DEV))

backup: ## pg_dump -Fc the stack db -> backups/pos-<utc>.dump (COMPOSE=prod for prod)
	@mkdir -p backups
	$(COMPOSE_VAR) exec -T db pg_dump -U pos -d pos -Fc > backups/pos-$$(date -u +%Y%m%dT%H%M%SZ).dump
	@ls -lh backups/ | tail -1

restore: ## Restore FILE=backups/... into the running db (DESTRUCTIVE, asks first)
	@test -n "$(FILE)" || { echo "usage: make restore FILE=backups/pos-....dump"; exit 1; }
	@read -p "Overwrite the live 'pos' database with $(FILE)? Type 'restore' to confirm: " a && [ "$$a" = "restore" ]
	$(COMPOSE_VAR) exec -T db dropdb -U pos --force pos && $(COMPOSE_VAR) exec -T db createdb -U pos pos
	$(COMPOSE_VAR) exec -T db pg_restore -U pos -d pos --no-owner < $(FILE)
	@echo "restored $(FILE)"

restore-drill: ## Prove the newest backup restores: throwaway db, row counts, teardown
	@test -n "$$(ls backups/*.dump 2>/dev/null)" || { echo "no backups yet - run 'make backup'"; exit 1; }
	@LATEST=$$(ls -t backups/*.dump | head -1); echo "drilling $$LATEST"; \
	docker run -d --name pos-drill -e POSTGRES_PASSWORD=drill postgres:18-alpine >/dev/null; \
	until docker exec pos-drill pg_isready -U postgres >/dev/null 2>&1; do sleep 1; done; \
	docker exec pos-drill createdb -U postgres pos; \
	docker exec -i pos-drill pg_restore -U postgres -d pos --no-owner < $$LATEST; \
	docker exec pos-drill psql -U postgres -d pos -tc \
	  "SELECT 'orders: '||count(*) FROM orders UNION ALL SELECT 'payments: '||count(*) FROM payments UNION ALL SELECT 'audit rows: '||count(*) FROM audit_log"; \
	docker rm -f pos-drill >/dev/null; \
	echo "restore drill PASSED - the backup is not a rumor"
```

- [ ] **Step 2: Prove it.** Dev stack up + seeded → `make backup` (file appears, non-trivial size) → `make restore-drill` (row counts > 0 print, container gone afterwards — `docker ps -a | grep pos-drill` empty). Then mutate (one more seeded sale via e2e or artisan tinker is unnecessary — instead) `make restore FILE=<that dump>` with the confirmation, and `make seed`-printed data still queryable via a quick `psql` count. Report outputs.

- [ ] **Step 3: Commit**

```bash
git add Makefile && git commit -m "M7: backups — dump, restore, and a restore drill that proves it"
```

---

### Task 7: CI `docker-build` job

**Files:**
- Modify: `.github/workflows/ci.yml`
- Test: pushed with the branch; verified green on the PR (Task 9 confirms)

**Interfaces:** none downstream.

- [ ] **Step 1: Append the job**

```yaml
  docker-build:
    name: Docker images build
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5

      - uses: docker/setup-buildx-action@v3

      # Prod images only — the point is that the Dockerfiles stay honest.
      - name: Build api image
        uses: docker/build-push-action@v6
        with:
          context: backend
          target: prod
          push: false
          cache-from: type=gha,scope=api
          cache-to: type=gha,scope=api,mode=max

      - name: Build register image
        uses: docker/build-push-action@v6
        with:
          context: frontend/web
          target: runner
          push: false
          cache-from: type=gha,scope=web
          cache-to: type=gha,scope=web,mode=max

      - name: Build back-office image
        uses: docker/build-push-action@v6
        with:
          context: frontend/back-office
          target: runner
          push: false
          cache-from: type=gha,scope=bo
          cache-to: type=gha,scope=bo,mode=max
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/ci.yml && git commit -m "M7: CI builds the three production images on every PR"
```

---

### Task 8: `make e2e` — the committed proofs against the dev stack

**Files:**
- Modify: `Makefile`
- Test: full run on this machine

**Interfaces:**
- Produces: `make e2e` — reseeds, extracts the printed credentials, exports the env the three scripts require (`POS_DEVICE_TOKEN`, `POS_DEVICE_TOKEN_2`, `POS_ADMIN_EMAIL`, `POS_ADMIN_PASSWORD`, `POS_E2E_PIN`), runs `scripts/e2e-retail-day.sh`, `e2e-lunch-service.sh`, `e2e-admin-day.sh` in order against `localhost:8000`.

- [ ] **Step 1: Makefile addition.** The seeder prints a credentials table; capture `make seed` output and parse the tokens (grep/awk on the known table format — read the seeder's actual print format first and pin the parse to it; if the format is awkward to parse, add `--compact` style machine lines is NOT allowed — parse what exists, or fall back to `artisan tinker` queries for the token values via `PersonalAccessToken`… simplest robust approach: a tiny `php artisan` snippet via `exec api php -r` is forbidden (artisan context needed) — use `$(COMPOSE_DEV) exec api php artisan tinker --execute=...` to mint/fetch tokens deterministically. Choose the simplest mechanism that works, document it in the target, and report which you chose.)

```makefile
e2e: ## Reseed, then run all three committed e2e proofs against the dev stack
	@$(MAKE) seed | tee /tmp/pos-seed-out.txt
	@# credential extraction pinned to the seeder's printed table (see seeder)
	...
	POS_E2E_PIN=9876 bash scripts/e2e-retail-day.sh && bash scripts/e2e-lunch-service.sh && bash scripts/e2e-admin-day.sh
```

(The `...` is the extraction block the implementer writes against the real seeder output — Step 2 proves it.)

- [ ] **Step 2: Run `make e2e`** on the dev stack — all three scripts exit 0 with their summary tables. This is the milestone's centerpiece proof: the containerized stack passes the same end-to-end stories the native stack did. Paste summaries in the report.

- [ ] **Step 3: Commit**

```bash
git add Makefile && git commit -m "M7: make e2e — the three committed proofs against the docker stack"
```

---

### Task 9: Docs + final verification

**Files:**
- Modify: `CLAUDE.md` (Running it → `make dev` front door, native path second; make targets table; gotchas if earned), `docs/01-architecture.md` (deploy topology section: one FrankenPHP edge, host routing, no-CORS survives prod), `docs/06-roadmap.md` (M7 status block in the house voice; M8 deferred list with triggers), `docs/README.md` if it indexes run instructions
- Test: full suites (all three surfaces, in containers via `make test`) + `make help` accuracy sweep

**Interfaces:** none — records and proves.

- [ ] **Step 1: Docs edits** — surgical; the roadmap M7 block records what building it taught (boot-time config caching vs baked env; the FrankenPHP-is-Caddy edge collapsing reverse-proxy+TLS+API into one container; env-driven API_ORIGIN keeping the no-CORS principle intact in every environment; the restore drill as a make target rather than a wiki page).
- [ ] **Step 2: Final sweep** — `make dev && make seed && make test && make e2e` from a `make clean` state (typed confirm), plus `make build`. All green = done-when met. Paste counts.
- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "M7: docs current — make dev is the front door"
```

---

## Plan self-review (performed at write time)

- **Spec coverage:** backend image (T1), frontend images + config changes (T2), dev compose + infra retirement (T3), Makefile core (T4), prod compose + TLS + env example (T5), backups + drill (T6), CI job (T7), e2e proof (T8), docs (T9). Deferred table items appear in no task — correct.
- **Placeholder scan:** Task 8 Step 1 contains a deliberate implementer-authored extraction block (`...`) — bounded, with the decision criteria and proof step specified; everything else is complete content.
- **Type/name consistency:** service names `db/api/web/back-office` consistent across compose files, Caddyfile reverse_proxy targets, and Makefile; image tags `pos-api/pos-web/pos-back-office` consistent between `make build` and CI; `POS_TLS_ISSUER` consistent between Caddyfile and compose env.
- **Machine-local safety encoded:** never edit phpunit.xml; check ports before binding; POS_DEV_DB_PORT default 5432 with 5434 for this machine's verification; infra/.env untouched.
