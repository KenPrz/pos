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
- Split allocation with deterministic remainder (1000 / 3 → 334, 333, 333; earliest
  absorbs).
- `Cents` branded type + formatter on the frontend.

**Done when:** the unit suite is green, including the penny-allocation property test
asserting parts always sum to the whole.

**Why first:** every later milestone calls this code. A rounding bug found here costs an
afternoon; found after go-live it costs a reconciliation.

**Status: complete.** `app/Domain/Money/` — `Money`, `Quantity`, `TaxRate`, `Discount`,
`Tender` — plus `frontend/web/src/lib/money.ts`. 132 backend tests, 29 frontend.

Decisions taken while building, worth knowing before M3 calls this code:

- **One rounding primitive.** `Money::fraction(n, d)` rounds half away from zero, and
  tax, discounts and fractional quantities are all expressed through it. One place a cent
  can be created or destroyed; one place to test.
- **`Money::parse()` exists but no float constructor does.** Admins type prices, so a
  string parser is necessary; it uses string arithmetic, and rejects a third decimal
  rather than rounding it, because silently discarding a digit is losing money quietly.
- **Discounts clamp to the base.** A $10 discount on a $5 item takes $5, never −$5.
  Unclamped, a "generous" discount turns a sale into a payout — a fraud surface, not a
  rounding detail.
- **`Tender` separates applied from tendered**, which is why `insufficient_tender` (422)
  now exists in `03-api.md`. Tendering less than the amount applied is impossible;
  *underpaying the order* is just a partial payment and perfectly legal.
- **Overflow fails with a reason.** PHP promotes integer overflow to float — the exact
  thing this code exists to prevent. Unreachable with real money ($10M at 100% is four
  orders of magnitude below `PHP_INT_MAX`), but guarded rather than assumed.

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

**Status: complete.** 191 backend tests. 40 tables, 28 check constraints, 6 partial
indexes, all verified to bite against real Postgres.

What building it changed, and what to know before M3:

- **Admin cannot be a spatie role.** `05-rbac.md` claimed a null team key makes a role
  global; it doesn't — it makes a role *definition* shared, while every *assignment* still
  pins to one location (the pivot's team column is in its primary key, so `NOT NULL`).
  Admin is now `users.is_admin` + `Gate::before`, which is spatie's own super-admin
  pattern. The doc is corrected.
- **PIN login needed a lookup index.** Bcrypt is salted, so login would have to check
  every candidate's hash — measured at 225ms each, twenty staff is a 4.5-second login. A
  keyed `pin_lookup` (HMAC with `APP_KEY`) makes it one indexed query; `pin_hash` stays
  the authority.
- **Sanctum had the same uuid problem as spatie** (`morphs` → `uuidMorphs`), which no
  document predicted.
- **`StaffLogin` must set the permission team context itself** — login runs before the
  middleware that normally does. Without it, the response's permission list is silently
  empty rather than wrong-and-loud. Exactly the failure `05-rbac.md` warns about.
- **Anything reading role assignments must query `model_has_roles` directly**, never the
  `roles()` relation — that relation scopes to the current team, so it silently answers a
  different question. Bit both `StaffDirectory` and `User::locationIds()`.
- **A constraint violation aborts the Postgres transaction**, and `RefreshDatabase` wraps
  each test in one. A test can provoke one violation, and nothing after it.

## M3 — The vertical slice

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

**Status: complete.** 255 backend tests, 29 frontend. A real sale runs scan → cart →
cash → change → receipt in a browser, and shift close reconciles the drawer.

What building it changed, and what to know before M4:

- **Validation failures are `400 validation_failed`, everywhere.** Two briefs assumed 422
  for a missing `Idempotency-Key` header; the envelope's actual split is 400 = the request
  is malformed, 422 = the request is well-formed but the domain refuses it. Tests must
  assert accordingly.
- **Eloquent never hydrates DB column defaults after `create()`.** An `Order` created
  without explicit version/totals carries PHP nulls in memory even though Postgres wrote
  0s. `OpenOrder` sets all six explicitly; any later action creating rows that lean on
  column defaults must do the same or `->refresh()`.
- **Postgres `jsonb` does not preserve object key order.** An idempotency replay is
  content-identical but not byte-identical to the original response. Tests compare
  replays with `toEqual`, never `toBe`.
- **Two concurrent first uses of one idempotency key: both run, one commits, the loser
  hits the key's unique PK and 500s** — but rolls back entirely, so "a replayed key
  charges once" holds. Accepted for v1; a retry after the 500 replays cleanly.
- **The two-connection stock concurrency test cannot run under `RefreshDatabase`** — the
  second connection can't see uncommitted rows. A plain PHPUnit class in `tests/Feature/`
  escapes Pest's `uses()` binding — subtle but deliberate; see `ConcurrentSaleTest`.
- **The register UI must keep a freshly-opened order in client state even when its first
  add-line fails** (insufficient stock) — otherwise every retry opens another server-side
  order, and open orders block shift close. Related accepted gap: an abandoned *empty*
  open order still blocks close until M4 ships void-order; the register-side remedy today
  is ringing the next sale onto it.
- **Staff sessions genuinely end at shift close** — `CloseShift` revokes the register's
  staff tokens; the UI returns to the PIN screen.
- **The seeder now prints a device token per register** (paste into the SPA's setup
  screen) and seeds stock through `StockLedger::receive`, so the ledger invariant holds
  from row one.

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

**Status: complete.** 346 backend tests, 35 frontend. A retail store can run a full day,
including the parts that go wrong: voids, discounts, refunds with restock, a standalone
card tender, stock corrections, and a shift close backed by a Z-report.

What building it changed, and what to know before M5:

- **The register moved mid-milestone**, at the owner's direction: Vite SPA → Next.js 16
  (app router) + TanStack React Query. The port kept the API client contract intact — one
  client boundary under a server shell, `/api` rewrites doing the same single-origin job
  the Vite dev proxy did. Next's built-in type-check can't drive TypeScript 7 (it misreads
  it as missing), so it's skipped; `tsc --noEmit` gates instead.
- **`DiscountResolver` review caught a real money bug before merge.** Allocating an
  order-level discount across lines fed zero-base lines into the penny allocator, and its
  remainder distribution could push a line's discount past its own base — a negative line
  total. Fixed by keeping only positive-remaining-base lines in the ratio array plus a
  clamped overflow walk; line-level discount rows now resolve sequentially against each
  line's *remaining* base, not its original one.
- **Piecewise refunds needed the same exact-split discipline M1 built for payments.**
  Refund amounts derive from qty fractions, and a line whose total doesn't divide evenly
  invented or lost a penny across several partial refunds — until the amount was *also*
  capped, with exhaustion taking the exact remainder. "Split sums exactly" turned out to
  apply to refunds too, not just tenders.
- **Voided orders keep their frozen totals.** Recalculating them is deliberately skipped,
  and nothing sums a voided order's totals for reporting — the payments/refunds ledgers
  are the source of truth there, never the order row.
- **The Z-report has to be fetched before the shift close lands.** Close revokes the
  register's staff sessions, so the close screen fetches the — already-final — running Z
  at mount, before the counted-cash round-trip that ends the session.
- **The register keeps the sale screen mounted while on Refunds or Close.** Unmounting it
  stranded an in-progress order server-side with no UI path back: open orders block shift
  close, and voiding a line needs the order on screen.
- **`external_card` proved the driver seam.** A new driver plus one validation rule, and
  zero changes to `TakePayment`, `VoidPayment`, or refunds.
- **The "money leaves" fraud-surface definition broadened.** It was written around till
  cash; a stock adjustment moves sellable value out the same way, so `05-rbac.md` now
  states the definition to cover both instead of treating `stock.adjust` as an exception.

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
