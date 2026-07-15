# POS

A point-of-sale system for a single business across multiple locations, serving both
retail and food service from one order model.

**Read `docs/README.md` first.** The design is written down and is the source of truth;
this file only covers how to run things.

## Stack

Laravel 13.20 (PHP 8.5) · PostgreSQL 18 · React 19 + TypeScript 7 + Vite 8 · Docker Compose

## Layout

```
backend/        Laravel API. Action-class architecture — see docs/04-backend-conventions.md
frontend/web/   React SPA (the register + back office)
frontend/native/  Reserved for a desktop shell (Electron/Tauri) — hosts the same SPA and
                  adds cash drawer + receipt printer access. Empty in v1.
infra/          docker-compose for local Postgres
docs/           The design. Start at docs/README.md
```

## Running it

Three things, in this order.

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

**3. SPA** — http://127.0.0.1:5173

```bash
cd frontend/web
npm install
npm run dev
```

Vite proxies `/api` to the API, so the browser sees one origin and CORS never comes up.

Check it works: <http://127.0.0.1:5173> should say **System healthy** and print the
Postgres version.

## Tests

```bash
cd backend && ./vendor/bin/pest         # needs Postgres up (creates/uses pos_test)
cd frontend/web && npm test && npx tsc -b --force && npm run build
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

Next: **M3 — the vertical slice** (`docs/06-roadmap.md`). Scan a barcode, ring up an item,
pay cash, get change, reconcile the drawer. It's the milestone that proves the
architecture while changing it is still cheap.

### Gotchas that will cost you an afternoon

- **Never read role assignments through spatie's `roles()` relation.** It applies
  `wherePivot(location_id, currentTeam)`, so it silently answers "roles at the location
  I'm already standing at" rather than "roles". Query `model_has_roles` directly. This
  has bitten twice.
- **The permission team context must be set before any `can()` or role load.** A stale or
  absent context returns *silently wrong* answers, never an error. `EnsureStaffSession`
  does it from the register; anything running outside that middleware must do it itself.
- **Admin is `users.is_admin`, not a role** — spatie's teams cannot express an assignment
  spanning locations. See `docs/05-rbac.md`.
- **A constraint violation aborts the whole Postgres transaction.** With `RefreshDatabase`,
  a test can provoke one violation and nothing after it.
