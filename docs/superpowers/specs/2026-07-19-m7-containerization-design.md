# M7 — Containerization: design

Owner-directed scope: **industry-standard Dockerfiles for deploy and dev, driven by a
Makefile** — plus the two pieces of the original M7 the prod compose naturally carries
(automatic TLS, backups with a runnable restore drill). Monitoring, load testing, the
runbook, and registry/CD defer to M8 with named triggers.

Owner's three strategy calls: **FrankenPHP** for the API image; **full Docker dev**
(`make dev` needs nothing but Docker); **TLS + backups ride along**.

**Done when:** `make dev` on a clean machine with only Docker yields a working full
stack (seed → sell at the register → see it in back office); `make prod-up` on a host
with DNS serves both apps over TLS; `make restore-drill` proves a backup restores.

## Images — three multi-stage Dockerfiles

### `backend/Dockerfile` (FrankenPHP, PHP 8.5)

- Base: the official FrankenPHP image for PHP 8.5; extensions `pdo_pgsql pgsql bcmath
  intl opcache` via `install-php-extensions`.
- Stages: `base` (runtime + extensions) → `vendor` (`composer install --no-dev`,
  layer-cached off `composer.lock` alone) → `prod`:
  - app code + vendor, owned by a non-root user;
  - opcache production settings (validate_timestamps=0);
  - entrypoint: waits for the DB healthcheck (compose `depends_on: condition`), runs
    `php artisan config:cache route:cache` at boot (env is only present then — never
    baked into the image), optionally `php artisan migrate --force` when
    `MIGRATE_ON_BOOT=1`;
  - `HEALTHCHECK` curls `/api/v1/health`;
  - Caddyfile mounted/templated per environment (see Composition) — the same binary
    serves dev and prod, config differs.
- `dev` target: `base` + composer dev deps; code arrives via bind mount at runtime, so
  the image rarely rebuilds during feature work.

### `frontend/web/Dockerfile` and `frontend/back-office/Dockerfile` (identical shape)

- Stages: `deps` (`npm ci`, cached off the lockfile) → `build` (`next build`; requires
  **`output: 'standalone'`** added to both next.configs) → `runner`:
  `node:24-alpine`, non-root, copies only `.next/standalone` + `.next/static` +
  `public` — the standard minimal Next production image; `PORT`/`HOSTNAME` env.
- `dev` target: `node:24-alpine` + bind-mounted source + `npm run dev`;
  `node_modules` lives in a named volume so host and container installs never collide.

### Postgres

Official `postgres:18-alpine`. No custom image. Named volume `pos-pgdata`,
`pg_isready` healthcheck, credentials via env.

### Code changes the images force (small, enumerated)

1. Both next.configs: rewrite destination becomes
   `process.env.API_ORIGIN ?? 'http://127.0.0.1:8000'` — containers set
   `API_ORIGIN=http://api:8000` (dev) / the internal service origin (prod); native dev
   untouched.
2. Both next.configs: `output: 'standalone'`.
3. `backend/.dockerignore`, `frontend/*/.dockerignore` (vendor, node_modules, .next,
   tests as appropriate — small images, fast contexts).

## Composition — two compose files at the repo root

### `compose.dev.yml` — `make dev`

Services: `db` (host port `${POS_DEV_DB_PORT:-5432}` — machine-local remaps like 5433
keep working via `.env`), `api` (dev target, `./backend` bind mount, `vendor` named
volume, port 8000), `web` (port 5174), `back-office` (port 5175) — both dev targets
with source bind mounts and `node_modules` volumes, `API_ORIGIN=http://api:8000`.
Hot reload works on all three app services. Native dev (artisan serve + npm run dev
against a Docker Postgres) remains fully supported and documented — the compose file
adds a path, it doesn't remove one.

### `compose.prod.yml` — `make prod-up`

- `api` (prod image) doubles as the **single public entrypoint**: FrankenPHP *is*
  Caddy, so its Caddyfile terminates TLS for both domains and routes by host:
  - `POS_REGISTER_DOMAIN` → `web:3000`; `POS_ADMIN_DOMAIN` → `back-office:3000`;
  - each Next app keeps its same-origin `/api` rewrite pointed at the api service —
    the no-CORS principle survives production end to end;
  - certificates auto-provisioned/renewed by Caddy (ports 80/443 published).
- `web`, `back-office` (prod images, internal network only), `db` (internal only,
  named volume).
- Secrets/config via an uncommitted `.env` beside the compose file;
  **`.env.prod.example`** documents every required var (`APP_KEY`, DB credentials,
  `POS_REGISTER_DOMAIN`, `POS_ADMIN_DOMAIN`, `POS_CURRENCY`, business fields, …).
  A missing required var fails loudly at boot (the M0 config guard already does this
  for the API).
- `infra/docker-compose.yml` (Postgres-only) retires: file replaced by a pointer
  comment; docs updated. CLAUDE.md's run instructions lead with `make dev`.

## Makefile — the runner surface

Root `Makefile`, self-documenting (`make help` via the standard `##` pattern),
`.PHONY` throughout, compose project name pinned. Targets:

| Target | Does |
| --- | --- |
| `dev` / `dev-down` / `logs` / `ps` | dev stack lifecycle |
| `seed` | `migrate:fresh --seed` in the api container — prints dev PINs/tokens |
| `migrate` | `artisan migrate` in the api container |
| `test` / `test-backend` / `test-web` / `test-bo` | suites inside the containers (backend pest against the compose db) |
| `typecheck` | tsgo in both frontend containers |
| `e2e` | runs the three committed e2e scripts against the dev stack (env-guarded creds as before) |
| `build` | builds all three prod images |
| `prod-up` / `prod-down` | prod stack lifecycle (requires the prod `.env`) |
| `backup` | `pg_dump -Fc` from the db container → `backups/pos-<timestamp>.dump` (dir git-ignored) |
| `restore FILE=…` | restore a dump into the running db (typed confirmation) |
| `restore-drill` | restores the newest backup into a **throwaway** container, verifies row counts (orders, payments, audit_log), tears down — the drill never touches live data |
| `clean` | down + remove volumes (typed confirmation) |

## CI tie-in

One added job in `ci.yml`: **`docker-build`** — `docker build` all three prod images
on every PR (no push, no registry). Keeps the Dockerfiles honest the way pest keeps
the actions honest. Depends on nothing else; runs in parallel.

## Testing / proof

- `make dev` from a clean checkout (fresh volumes): stack up, `make seed`, register
  sale via the existing e2e scripts (`make e2e`), back office login — all green.
- `make build` produces all three images; `docker run` smoke: api healthcheck green,
  Next runners serve.
- `make backup` → `make restore-drill` green with real seeded data.
- Prod compose validated with `docker compose config` + a local boot using
  self-signed/internal TLS (Caddy `internal` issuer when domains aren't real) — real
  ACME certs are proven on first actual deployment, documented as such.
- CI `docker-build` job green.

## Deferred to M8, with triggers

| Deferred | Revive when |
| --- | --- |
| Monitoring / alerting / log shipping | First real deployment day |
| Load test at lunch-rush concurrency | First pilot store scheduled |
| Runbook | First operator who isn't us |
| Registry + CD pipeline | First remote host to deploy to |
| FrankenPHP worker mode (Octane) | Measured latency need — off by default, the image already supports it |
