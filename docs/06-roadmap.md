# Roadmap

Sequenced so that **every milestone ends with something you can actually run**. No
milestone is "build the service layer." The ordering principle: the riskiest and most
expensive-to-change decisions get exercised by real code first.

## M0 — Skeleton that boots

- `infra/docker-compose.yml`: `postgres:18-alpine`, volume, healthcheck.
- `backend/`: Laravel 13, Sanctum, Postgres connection, `/api/v1/health`.
- The `04-backend-conventions.md` skeleton: `app/Actions`, `app/Domain`,
  `app/Exceptions/Domain` with the `DomainException` base and its render hook, and the
  directory layout. `/api/v1/health` is built **as a real action** — controller, action,
  resource — so the very first endpoint sets the shape every later one copies.
- `frontend/web/`: Vite 8 + React 19 + TS 7, hits `/health`, renders the result.
- `git init`, `.gitignore`, CI running `pest` + `tsc -b`.
- `CLAUDE.md` documenting how to run all of it.

**Done when:** `docker compose up`, two dev servers, and a browser page that says the API
and database are alive.

**Status: complete.** Notes from actually building it, for whoever hits the same walls:

- `postgres:18` moved the recommended mount to `/var/lib/postgresql` (not `.../data`);
  mounting the old path makes the container restart-loop on first boot.
- Laravel's `phpunit.xml` ships pointing at in-memory SQLite. Repointed at real Postgres
  per the testing rule above — this is deliberate, not an oversight.
- The Vite template sets `erasableSyntaxOnly`, which forbids constructor parameter
  properties. Type syntax must never emit runtime code.
- The framework's own exceptions (404, 405, validation) needed explicit mapping into the
  error envelope; only handling `DomainException` leaves Laravel's default shape leaking
  through, which breaks the one-code-path promise in `03-api.md`.

## M1 — Money primitives

Before any schema. These are pure integer functions with no I/O, they're where the
expensive bugs live, and they are the foundation everything else computes on.

- `Money` value object (int cents; no float constructor exists — not "discouraged",
  *absent*).
- Tax: exclusive and inclusive extraction, per-line, half-up.
- Percent and fixed discounts.
- Change calculation.
- Split allocation with deterministic remainder (1000 / 3 → 333, 333, 334).
- `Cents` branded type + formatter on the frontend.

**Done when:** the unit suite is green, including the penny-allocation property test
asserting parts always sum to the whole.

**Why first:** every later milestone calls this code. A rounding bug found here costs an
afternoon; found after go-live it costs a reconciliation.

## M2 — Schema + auth

- All migrations from `02-data-model.md`, including the partial indexes and checks.
- Models, factories, seeders (two locations, a few products, staff at each role).
- Register enrollment; device tokens.
- Staff PIN login, rate limiting, the PIN-collision check on set.
- `spatie/laravel-permission` per `05-rbac.md`: publish and **edit the migrations for
  uuid** team/morph keys, enable teams on `location_id`, seed the permission catalog and
  roles, set team context in `EnsureStaffSession`.
- `config/pos.php` per `04-backend-conventions.md`.

**Done when:** you can enroll a register, log in with a PIN, and be refused when the
permission is missing. Tests cover the PIN collision check, the lockout, and the
per-location roles test (same user, two registers, different `can()`).

**Watch:** the uuid migration edits are known and listed, but they're the likeliest thing
to eat an afternoon. Do them first, before any model work depends on the tables existing.

## M3 — The vertical slice 🎯

**Scan a barcode, ring up an item, pay cash, get change, print a receipt.**

- Open shift with a float.
- `GET /catalog`, barcode lookup.
- Open order, add line (snapshots + stock decrement in one transaction).
- Cash payment, change, auto-close on paid in full.
- Receipt JSON from snapshots.
- Register UI: scan → cart → tender → change → receipt.
- Close shift, count, variance.

**Done when:** a real sale runs end-to-end in a browser and the drawer reconciles.

**Why this is the milestone that matters:** it exercises money, snapshots, stock locking,
idempotency, shifts, and auth *together*. Everything after it is addition; everything
before it is preparation. If the architecture is wrong, this is where it shows — while
it's still cheap.

## M4 — Retail complete

- Variants with options; per-location price overrides.
- Discounts (line and order), supervisor gating.
- Void line, void order, reopen.
- Refunds with per-line restock.
- Stock: adjustments, receiving, counts, movement history.
- `external_card` driver.
- Z-report.

**Done when:** a retail store could run a full day, including the parts of a day that go
wrong — returns, voids, miscounts.

## M5 — Food service complete

- Open tabs, `table_ref`, floor/tab list view.
- Modifiers end-to-end: groups, min/max validation, price deltas, receipt display.
- Split payments across tenders.
- Transfer an order between staff.
- Register UI mode switch (menu grid vs. scanner).

**Done when:** a cafe could run a lunch service: open a tab, add courses over an hour,
split three ways.

**Why after retail:** food service is retail plus a longer open phase plus modifiers. The
whole thesis in `00-overview.md` is that this milestone adds *screens*, not tables. If it
turns out to need a schema change, the thesis was wrong and we want to learn that with M4
already earning.

## M6 — Back office

- Catalog CRUD; user management; location and register settings.
- Sales reports (by day, category, user); stock and low-stock reports.
- Audit log viewer.

**Done when:** an admin never needs `psql`.

## M7 — Production

- Deploy topology, TLS, backups (and a **restore drill** — an untested backup is a
  rumor).
- Structured logging, error tracking, uptime alerting.
- Load test at realistic lunch-rush concurrency.
- Runbook: register won't connect, drawer won't reconcile, restore from backup.

**Done when:** it's live and someone other than us can operate it at 2am.

---

## Sequencing rationale

- **Money before schema** — everything computes on it.
- **Vertical slice before breadth** — one thin sale proves the architecture while
  changing it is still cheap. Building all of retail before the first end-to-end sale
  means discovering a foundational mistake with five milestones stacked on it.
- **Retail before food service** — retail is the shorter path through the same
  lifecycle, so it validates the shared core with less surface area.
- **Back office last** — it has no customer waiting at a counter. `psql` and seeders
  cover the gap until then.

## Deferred, with the trigger that revives each

Not "maybe someday" — each has a specific condition that should promote it.

| Deferred | Revive when |
| --- | --- |
| Desktop shell (`frontend/native/`) | A pilot store needs a real cash drawer and thermal printer. Until then: browser print dialog, drawer opened by hand. The seam is in `01-architecture.md` — server decides what, shell does how. |
| Offline-tolerant writes | The first outage costs a real shift's revenue. The idempotency table is already the replay mechanism, and the desktop shell would be its host. |
| Stripe Terminal | Someone wants card money to flow through our reports instead of a separate reader. |
| Kitchen display | A kitchen asks. `order_lines.prep_state` is already there. |
| Queue + Redis | The first thing worth doing async — realistically, emailed receipts. |
| Multi-tenancy | Selling this to a second business. **Costly by then, so decide early, not when the contract's signed.** |
| Loyalty / gift cards | A concrete promotion needs it. |

## Risks

- **Offline.** The known, accepted cost of v1 (`00-overview.md`). Mitigation is the
  idempotency groundwork, not denial.
- **Tax complexity.** Inclusive/exclusive is handled. Multi-jurisdiction US sales tax is
  *not*, and would be a real project.
- **Penny allocation.** Contained, and tested in M1 rather than discovered in M5.
- **The unified model breaking down.** If food service turns out to need schema changes
  rather than screens, M5 is where we learn it. That risk is why M5 is late and M4 is
  early — the ordering is a hedge.
