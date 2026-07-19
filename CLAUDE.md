# POS

A point-of-sale system for a single business across multiple locations, serving both
retail and food service from one order model.

**Read `docs/README.md` first.** The design is written down and is the source of truth;
this file only covers how to run things.

## Stack

Laravel 13.20 (PHP 8.5) · PostgreSQL 18 · React 19 + TypeScript 7 on Next.js 16 + React Query · Docker Compose

## Layout

```
backend/              Laravel API. Action-class architecture — see docs/04-backend-conventions.md
frontend/web/         Next.js register app
frontend/back-office/ Next.js back-office app (M6) — catalog/user/location CRUD, reports, audit
frontend/native/      Reserved for a desktop shell (Electron/Tauri) — hosts the register
                      app and adds cash drawer + receipt printer access. Empty in v1.
Makefile               Runner surface for the containerized stack — `make help` lists everything.
compose.dev.yml        Full dev stack: db + api + web + back-office, hot reload. `make dev`.
compose.prod.yml       Single-host production: one FrankenPHP edge, TLS, host routing. `make prod-up`.
infra/                 Retired (M7) — was a standalone Postgres-only compose; compose.dev.yml replaced it.
docs/                  The design. Start at docs/README.md
```

Two separate frontends, not one app split by route: the register (device-token auth,
hardware seam) and the back office (email/password auth, no device or location context)
are different enough sessions that a shared build bought nothing. See `01-architecture.md`.

## Running it

**`make dev` is the front door.** One Postgres, one API, both frontends, all in
containers, hot-reloading against your working tree — nothing but Docker needed.

```bash
cp -n .env.example .env        # first time only
make dev-key                   # first time only — mints POS_DEV_APP_KEY; paste into .env
make dev                       # db + api + register + back office, hot reload
make seed                      # fresh migrate + seed — prints dev PINs and device tokens
```

http://127.0.0.1:8000 (API) · http://127.0.0.1:5174 (register) · http://127.0.0.1:5175
(back office). `make help` lists every target; the ones you'll reach for most:

| Target | Does |
| --- | --- |
| `make dev` / `make dev-down` | Bring the dev stack up / down (volumes survive `dev-down`) |
| `make seed` / `make migrate` | Fresh migrate + seed / pending migrations only |
| `make test` | All three suites, in containers (`test-backend`/`test-web`/`test-bo` individually) |
| `make e2e` | The three committed end-to-end proofs against the running stack |
| `make build` | Build all three production images |
| `make backup` / `make restore` / `make restore-drill` | Dump the db, restore a dump, prove the dump actually restores |
| `make clean` | Stack down **and volumes destroyed** — asks first |

Full recipes: `Makefile` at the repo root. Compose files: `compose.dev.yml` /
`compose.prod.yml`. Deploy topology (prod): `docs/01-architecture.md`.

### Native path (no Docker)

Still fully supported — the dev compose adds a path, it doesn't remove one. Four
things, in this order (the fourth only if you need the back office).

**1. Postgres**

```bash
cd infra
cp -n .env.example .env
docker compose up -d          # postgres:18-alpine on :5432
```

**2. API** — http://127.0.0.1:8000

```bash
cd backend
cp -n .env.example .env && php artisan key:generate   # first time only
composer install
php artisan migrate
php artisan serve
```

**3. SPA** — http://127.0.0.1:5174

```bash
cd frontend/web
npm install
npm run dev
```

Next rewrites `/api` to the API, so the browser sees one origin and CORS never comes up.

Check it works: <http://127.0.0.1:5174> should say **System healthy** and print the
Postgres version.

**4. Back office** — http://127.0.0.1:5175

```bash
cd frontend/back-office
npm install
npm run dev
```

Same `/api` rewrite pattern as the register app, same single-origin story. Log in with
an admin's email and password (`POST /api/v1/admin/login`) — the seeder prints one; see
Status, below.

## Tests

`make test` runs all three suites inside the containers (creates `pos_test` in the
compose db if missing). Natively:

```bash
cd backend && ./vendor/bin/pest         # needs Postgres up (creates/uses pos_test)
cd frontend/web && npm test && npm run typecheck && npm run build
cd frontend/back-office && npm test && npm run typecheck && npm run build
```

The test database is created once:

```bash
docker exec pos-postgres psql -U pos -d pos -c "create database pos_test owner pos;"
```

**Tests run against real Postgres, never SQLite.** We depend on partial unique indexes,
`SELECT ... FOR UPDATE`, `jsonb`, and `uuidv7()`; SQLite silently lacks all of them, so a
green SQLite suite would actively mislead about whether our concurrency invariants hold.
`phpunit.xml` is configured this way deliberately — don't "fix" it back.

`tests/Arch/` enforces the conventions in `docs/04-backend-conventions.md` mechanically
(actions never touch HTTP, actions are final, no `env()` outside config, strict types).
If one fails, the code broke a documented rule — change the code, not the rule.

## Conventions that will bite you if you skip them

Full reasoning lives in the docs; these are the ones that cause real damage.

- **Money is integer cents** (`bigint` / PHP `int`). Never a float, in any layer, ever.
  Wire format is an integer with a `_cents` suffix. Use `App\Domain\Money\Money` — it has
  no float constructor, deliberately. → `docs/01-architecture.md`
- **All rounding goes through `Money::fraction()`.** Tax, discounts, and fractional
  quantities are all fractions of an amount, so there is one place a cent can be created
  or destroyed. Don't add a second.
- **Quantities are strings** on the wire (`"0.500"`). `numeric(12,3)` does not survive
  IEEE-754, and JS `number` is IEEE-754. → `docs/03-api.md`
- **One system action = one route = one controller = one Action class.** Actions take an
  Input DTO, return a domain object, and know nothing about HTTP. Serialization is the
  controller's job. → `docs/04-backend-conventions.md`
- **Config is what engineers deploy; the database is what admins change at runtime.**
  Never both. Never call `env()` outside `config/`. → `docs/04-backend-conventions.md`
- **Financial records are append-only.** A refund is new rows; a closed order is never
  mutated. → `docs/00-overview.md`
- **Order lines snapshot** name, SKU, price, and tax rate. A receipt from last year must
  reprint identically, so never join to the live catalog to render one.

## Where things are

- Current milestone and what's next → `docs/06-roadmap.md`
- Why online-only / single-tenant / cash-first → `docs/00-overview.md`
- Schema → `docs/02-data-model.md`
- Endpoints and error codes → `docs/03-api.md`
- Roles and permissions → `docs/05-rbac.md`

## Status

**M0 complete** — skeleton boots end to end. `GET /api/v1/health` is built as a real
action (controller → action → resource) so the first endpoint sets the shape every later
one copies.

**M1 complete** — money primitives in `app/Domain/Money/` and `frontend/web/src/lib/money.ts`.
Pure integer functions, no I/O, no container.

**M2 complete** — full schema (40 tables), register enrolment, PIN login, per-location
RBAC. Seed with `php artisan migrate:fresh --seed`; it prints development PINs.

**M3 complete** — the vertical slice: scan → cart → cash → change → receipt, plus shift
open/close with variance. Seeder prints development device tokens for the register SPA.

**M4 complete** — retail: voids, discounts, refunds with restock, external card, cash
movements, stock ops, Z-report; register UI restyled to DESIGN.md console chrome and
ported to Next.js + React Query.

**M5 complete** — food service: open tabs with `table_ref`, modifiers end-to-end
(repeats legal), fired-course coursing (`prep_state`), qty edits, transfer between
registers, three-way-and-more splits, drawer-variance approval. `registers.mode` picks
the register UI (menu grid vs. scanner); zero new order-model tables. Seed and run
`scripts/e2e-lunch-service.sh` for the full story end to end.

**M6 complete** — back office: catalog CRUD, user management (roles are a full-set
replace), location and register settings (mode, device-token reissue), sales reports
(day/user are ledger-basis, category is line-basis — they don't reconcile, and the
response says which is which), stock/low-stock report, audit log viewer. Admin-only
auth (`POST /api/v1/admin/login`), archive-never-delete throughout (no `DELETE` route
anywhere under `/admin/*`). Seed and run `scripts/e2e-admin-day.sh` for the full story
end to end.

**M7 complete** — containerization: three images (FrankenPHP API, two identical-shape
Next.js runners), one dev compose (`make dev` — nothing but Docker needed) and one prod
compose (single FrankenPHP edge, host-routed TLS, no-CORS preserved end to end),
backups with a runnable restore drill, CI building all three images on every PR. Same
462/80/80 tests, now proven inside the stack via `make test`; all three e2e scripts
green via `make e2e`. Full story in `docs/06-roadmap.md`.

Next: nothing scheduled. `docs/06-roadmap.md`'s deferred table has what's left and the
trigger that would revive each (monitoring, load test, runbook, registry/CD, and more).

### Gotchas that will cost you an afternoon

- **Never read role assignments through spatie's `roles()` relation.** It applies
  `wherePivot(location_id, currentTeam)`, so it silently answers "roles at the location
  I'm already standing at" rather than "roles". Query `model_has_roles` directly. This
  has bitten three times now — most recently the M6 back office's own role writes,
  which have no register to stand at in the first place.
- **A Postgres `CHECK` constraint is evaluated after every statement, not once at
  commit.** A multi-statement transaction that satisfies an invariant only at the very
  end (e.g. clearing a user's email while a PIN hash is set in the same request) can
  still fail mid-transaction if an earlier statement leaves the row in a state the
  CHECK sees and rejects. Order the writes so every intermediate statement already
  satisfies the constraint on its own — see `UpdateUser`'s roles → PIN → columns
  ordering.
- **The permission team context must be set before any `can()` or role load.** A stale or
  absent context returns *silently wrong* answers, never an error. `EnsureStaffSession`
  does it from the register; anything running outside that middleware must do it itself.
- **Admin is `users.is_admin`, not a role** — spatie's teams cannot express an assignment
  spanning locations. See `docs/05-rbac.md`.
- **A constraint violation aborts the whole Postgres transaction.** With `RefreshDatabase`,
  a test can provoke one violation and nothing after it.
- **Eloquent `create()` never hydrates DB column defaults** — set them explicitly or
  `->refresh()`.
- **`jsonb` reorders keys** — idempotency replays are content-identical, not
  byte-identical (`toEqual`, never `toBe`).
- **Next can't drive TypeScript 7, and without a stable `typescript` it
  self-heals with a mid-build `npm install` that breaks on CI runners.** Both apps
  therefore carry stable `typescript` (for Next) plus `@typescript/native-preview`
  (tsgo); `npm run typecheck` is the gate, Next's own check stays disabled.
- **The Z-report is fetched before close** — closing revokes the register's staff
  sessions.
- **Approving a variance from the register that just closed will 401** — `CloseShift`
  revokes every staff session bound to that register, and approval needs a session like
  any other write. Approve from a *different* register at the same location instead (the
  check is on location, not the specific terminal) — see `scripts/e2e-lunch-service.sh`.
- **Idempotency keys are a global primary key, not scoped per route or per order.**
  Reusing one on a genuinely different request anywhere in the system is
  `409 idempotency_key_reused`, even across unrelated endpoints. Don't assume "different
  path, same key" is safe.
- **`docker compose exec` defaults to root.** The dev containers drop to a non-root
  user for good after boot (see `docs/06-roadmap.md`'s M7 notes), but `exec` reconnects
  as root unless told otherwise — a command run against a bind-mounted service (api,
  web, back-office) needs `--user pos` or `--user node` explicitly, or it can leave
  root-owned files under the bind mount. The Makefile's targets already do this; a
  hand-run `docker compose exec` needs to as well.
- **The Compose project name `pos` is a collision, not a coincidence.** Both
  `compose.dev.yml` and `compose.prod.yml` name their project `pos`, which claims the
  `pos_pgdata` volume outright. A host that ever ran the retired
  `infra/docker-compose.yml` (same default project name) attaches to that same volume —
  a real database, not a fresh one — unless it's torn down with `-v` first or the new
  stack boots under an overridden `COMPOSE_PROJECT_NAME`.
