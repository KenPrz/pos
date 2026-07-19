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

**Status: complete.** 387 backend tests, 78 frontend. `scripts/e2e-lunch-service.sh` runs
a full lunch service against a freshly seeded stack: two tabs on two registers, modifiers
including a repeated one, a fired course, a qty bump on that fired line, a transfer, a
three-way split paid across cash and card, a forced-and-approved drawer variance, and a
clean reconciling close.

What building it changed, and what to know before M6:

- **The thesis held.** M5's entire schema cost is **one new column**
  (`registers.mode`, plus its own `check`) and **one new constraint** — a paired
  `check` on `shifts.variance_approved_by`/`_at`, columns that already existed,
  forward-declared nullable at M2. Zero new order-model tables. `table_ref` (M2),
  `prep_state` (M2, unused until now), and the whole order/line/payment lineage from
  M3–M4 needed no shape change at all, only new actions reading and writing the columns
  already there. The risk this milestone existed to retire — "food service needs a
  parallel model" — didn't
  materialize.
- **Split's exactness discipline is the same one M1 built for payments, applied per
  child.** Every allocated column (qty in milli, line total, tax, modifiers, each
  discount row) runs through the earliest-absorbs-the-remainder allocator once, and
  children's totals are *summed* from those allocated parts afterward, never
  recomputed independently — recomputing 1/N of a tax would mint or lose pennies that
  the sum-of-parts approach can't. The original order is closed out **voided, without
  restock** — stock left the ledger when the lines were first added and the children
  inherit that claim, so restocking on split would double the stock and understate the
  sale.
- **Prep state deliberately carries no `If-Match` and bumps no `version`.** A kitchen
  tapping "fired" or "ready" races nothing at the till — a version bump there would
  invalidate an in-flight tender for a reason the cashier can't see. The trade is a
  known, accepted one: `SetLinePrepState` is lock-free, so a prep update racing a
  same-line void can land after the void. Order-line financial writes still lock and
  version as before; only the coursing verb is exempt, because it isn't money.
- **The blind-count screen from M4 needed a correction, not an addition.** M4's close
  screen showed the expected cash before the count, which lets a lazy cashier just
  retype the number back. M5's variance-approval flow only has teeth if the count is
  real, so the close screen now asks for the counted amount *first* and reveals
  expected/variance only after — the same UI, a different order of two fields, and a
  fix that belongs to M4's feature even though M5 is what surfaced the gap.
- **The idempotency-key invariant is stricter than the plan assumed, and better.** The
  plan brief guessed a key could be scoped "per path" so the same key on two different
  endpoints would both execute. It can't: `idempotency_keys.key` is a bare primary key
  with no path or order in it, so reusing one anywhere in the system for a genuinely
  different request is `409 idempotency_key_reused`, full stop. `01-architecture.md`
  already documented the real shape; the plan brief was the thing that drifted, and
  this is the correction landing in the one place a client actually reads it.
- **Approving a variance from the register that just closed 401s**, because closing
  revokes every staff session bound to that register. Not a bug: approval happens from
  a different register at the same location (the check is on location, not the specific
  terminal), or from the M6 back office once that exists. `scripts/e2e-lunch-service.sh`
  approves from the terminal that's still open for exactly this reason.
- **Decreasing a fired line's quantity shares its permission with voiding a sent
  line**, decided inside `UpdateLineQty` rather than as a new named permission —
  shrinking a course the kitchen already started is the same fraud surface as pulling
  it off the ticket outright. Increasing needs no such gate.

## M6 — Back office

- Catalog CRUD; user management; location and register settings.
- Sales reports (by day, category, user); stock and low-stock reports.
- Audit log viewer.

**Done when:** an admin never needs `psql`.

**Status: complete.** 462 backend tests, 80 register-app tests, 80 back-office-app
tests. `scripts/e2e-admin-day.sh` runs a full admin day against a freshly seeded stack:
build a menu item from nothing (category, tax rate, product, variant, modifier group +
modifiers, attach), hire a cashier, switch a till to food mode and reissue its device
token (the old one is dead before the script's next line), ring a sale on the new
token, reprice the sold variant from the back office and prove the paid order's receipt
didn't move, read the same sale back through all three sales-report slices and the
audit log, and close the shift clean.

What building it changed, and what to know before M7:

- **The M2 schema finally earned its keep.** `users.email`/`password_hash`, the
  `POST /registers/enroll` admin-session precedent, and half the permission catalog
  (`catalog.manage`, `user.manage`, `location.manage`, `register.enroll`, `audit.view`)
  were forward-declared four milestones ago and sat unused until now. Nothing about them
  needed to change to carry the whole back office — the schema and the permission names
  were right the first time, which is the payoff for having named the fraud surface
  (`05-rbac.md`) before there was a screen sitting on top of it.
- **Archive-never-delete is the CRUD spine, not a policy bolted onto it.** There is no
  `DELETE` route anywhere under `/admin/*` — a category, product, variant, modifier,
  discount, tax rate, location, or register is retired with `PATCH { "is_active": false
  }`. Deciding this once, in the first catalog task, gave every later entity (users,
  locations, registers) the same shape for free instead of relitigating "can we delete
  this" seven times.
- **Reports have two honest bases, and they're not required to reconcile.** `sales`
  grouped by `day`/`user` is *ledger*-basis — summed from captured `payments` and
  `refunds`, money that actually moved. Grouped by `category` it's *line*-basis —
  summed from order lines, joined to the *live* catalog for a category name, which a
  report may do and a receipt never may. The resource's `basis` field says which is
  which, so the back office never implies a single number both slices would agree on
  when they have no reason to.
- **Per-location roles bit again, in exactly the shape M2 warned about.**
  `RoleAssignments` had to read and write `model_has_roles` directly a second time —
  spatie's `roles()` relation still only answers "roles at the location I'm standing
  at," and a back-office write has no register to stand at in the first place. Same
  gotcha, second implementation, same fix; see CLAUDE.md.
- **A Postgres `CHECK` is evaluated after every statement, not once at commit.**
  `UpdateUser` writes roles, then a PIN, then the plain columns, in that order, inside
  one transaction, because `users_can_authenticate` (email or PIN hash not null) would
  otherwise see an intermediate state — nulling the email before a PIN hash is on the
  row fails a constraint that the *finished* transaction would have satisfied. The CHECK
  doesn't know the transaction isn't done yet.
- **A resource that only exposes an attach relationship on some responses is an
  attach-blindness bug.** `AdminProductResource` originally carried `modifier_groups`
  only where the caller had eager-loaded the pivot; a full-set-replace attach editor
  seeded from a response that omitted it would save back an empty set and detach every
  group the product actually had. The fix was a second, unconditionally-present field
  (`modifier_group_ids`), and the lesson generalizes past this one endpoint: any
  full-set-replace write needs its read side to expose the current set on every
  response, not just the ones that happened to eager-load it.
- **Reissuing a device token kills the old one inside the same transaction.** `POST
  /admin/registers/{id}/token` deletes every existing personal-access token for that
  register and mints the replacement before either write commits, so there's no window
  where a lost terminal's old credential and its replacement are both live.
- **Admin-only back-office auth was a scope decision, not an oversight.** `/admin/login`
  has no register and no device, so it has no team context for spatie's per-location
  roles to hang off — the same reason `admin` isn't a role at all. A read-only
  supervisor/bookkeeper tier is a named deferral, waiting on the first accountant who
  needs sales and audit visibility without order-void or user-management power;
  designing it now, with no real user to shape it around, would be guessing at the
  wrong problem.

## M7 — Production

- Deploy topology, TLS, backups (and a **restore drill** — an untested backup is a
  rumor).
- Structured logging, error tracking, uptime alerting.
- Load test at realistic lunch-rush concurrency.
- Runbook: register won't connect, drawer won't reconcile, restore from backup.

**Done when:** it's live and someone other than us can operate it at 2am.

**Status: complete**, scoped at the owner's direction to what containerizing actually
needs — industry-standard Dockerfiles driven by a Makefile — plus the two pieces the
production compose naturally carries: automatic TLS and a runnable restore drill.
Monitoring, load testing, the runbook, and a registry/CD pipeline are named deferrals
below, not gaps nobody noticed. `make dev` on a machine with nothing but Docker
installed brings up the full stack — db, api, register, back office — hot-reloading
against the working tree; `make prod-up` on a host with DNS serves both apps over TLS
from one edge; `make restore-drill` proves a backup restores into a throwaway
container. 462 backend / 80 register / 80 back-office tests — unchanged, since
containerizing touched no application code — now run inside the stack itself via
`make test`, and all three committed end-to-end scripts run against it via `make e2e`.

What building it changed, and what to know operating it:

- **Config is cached at boot, never baked into the image.** `php artisan
  config:cache`/`route:cache` need `POS_CURRENCY`/`POS_BUSINESS_NAME`/`APP_KEY` to boot
  the framework at all, and none of those exist at `docker build` time — only at
  container start, when real env is present. The prod Dockerfile's `composer
  dump-autoload --no-scripts` exists specifically to skip package discovery, which
  would otherwise try to boot the framework mid-build and fail on the same missing
  vars; discovery and config caching both happen once, at first boot, when the env is
  real.
- **FrankenPHP *is* Caddy**, which collapses reverse proxy, TLS termination, and the
  PHP runtime into one container. `compose.prod.yml`'s `api` service is the single
  public entrypoint: its Caddyfile terminates TLS for both domains (auto-provisioned,
  auto-renewed), routes `/api/*` to itself, and reverse-proxies everything else to
  `web:3000` or `back-office:3000` by host. One image is the edge; there is no separate
  nginx or load balancer in front of it.
- **`API_ORIGIN` keeps the no-CORS principle alive in every environment.** Both
  Next.js apps' `/api` rewrite reads `process.env.API_ORIGIN`; native dev falls back to
  `http://127.0.0.1:8000`, dev compose sets `http://api:8000`, and prod needs nothing at
  all — Caddy already routes `/api/*` to the api service before the request reaches a
  Next server. The browser has seen exactly one origin from M0 through prod; only the
  value behind the rewrite ever changed.
- **The dev containers drop root the moment they've done the one thing that needs
  it.** A fresh named volume (`api_vendor`, `*_node_modules`) is root-owned by Docker,
  and the image's own non-root user can't write into it on first boot. Each dev service
  starts as root *only* to `chown` that volume once (a single `stat`, skipped on
  restart), then `exec su`s to the matching non-root user for the rest of the
  container's life. The host bind-mounted tree itself is never touched by root —
  verified with `find backend frontend -user root` coming back empty after a full
  `make clean && make dev` cycle. `docker compose exec` is a separate hazard from this:
  it defaults to root regardless, so every Makefile target that touches a bind mount
  names `--user pos`/`--user node` explicitly; see CLAUDE.md.
- **The restore drill is a `make` target, not a wiki page.** `make restore-drill`
  spins up a throwaway Postgres container, restores the newest backup into it, prints
  row counts, and tears down, so "the backup works" is provable on demand instead of
  assumed. Proven for real, not just plausible: `dropdb --force` against a database
  with an *active held connection* (not merely an idle one) genuinely terminates it and
  drops the database — the exact case a stale connection would otherwise block, and the
  one `make restore` itself relies on.
- **`make e2e` reseeds twice, on purpose.** All three committed end-to-end scripts
  transact at the same location on the same calendar day; `e2e-admin-day.sh`'s
  sales-report assertions are absolute counts, proven standalone in M6 against its own
  fresh seed. Running it after the other two scripts (which also transact there, same
  day) makes that one assertion false without anything actually being broken — so
  `make e2e` reseeds once before the retail/lunch scripts and again before admin-day,
  restoring the exact precondition admin-day was written against. Reordering instead
  (admin-day first) doesn't work either: admin-day flips a till to food mode and
  reissues its device token, which the lunch script depends on still being retail-mode.
  The target leaves the dev db dirty on purpose afterward — see its own `make help`
  line.
- **The Compose project name `pos` is a real collision hazard, not a cosmetic
  choice.** Both compose files name their project `pos`, which claims the `pos_pgdata`
  volume outright; a host that ever ran the retired `infra/docker-compose.yml` (same
  default project name) attaches to that same volume — a real database, not a fresh one
  — unless it's torn down with `-v` first or the new stack boots under an overridden
  `COMPOSE_PROJECT_NAME`. Documented in the compose files themselves, not just here.

Next: nothing scheduled. See the deferred table below — M7 added five ops-shaped rows
to it (monitoring, load test, runbook, registry/CD, worker mode) plus two hardening
items surfaced while proving the restore drill and `make e2e`.

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
| Monitoring / alerting / log shipping | First real deployment day. |
| Load test at lunch-rush concurrency | First pilot store scheduled. |
| Runbook (register won't connect, drawer won't reconcile, restore from backup) | First operator who isn't us. |
| Registry + CD pipeline | First remote host to deploy to. |
| FrankenPHP worker mode (Octane) | Measured latency need — off by default; the image already supports it. |
| Delta-based `e2e-admin-day.sh` assertions | The e2e scripts need to compose without `make e2e`'s double-reseed — today its sales-report checks are absolute counts that only hold against its own fresh seed. |
| `COMPOSE_VAR` hardening against a typo'd `COMPOSE=` | A destructive `backup`/`restore`/`restore-drill` target is run with a mistyped `COMPOSE=prod` and silently falls back to the dev stack instead of failing loudly. |

## Risks

- **Offline.** The known, accepted cost of v1 (`00-overview.md`). Mitigation is the
  idempotency groundwork, not denial.
- **Tax complexity.** Inclusive/exclusive is handled. Multi-jurisdiction US sales tax is
  *not*, and would be a real project.
- **Penny allocation.** Contained, and tested in M1 rather than discovered in M5.
- **The unified model breaking down.** Retired: M5 shipped as one new column
  (`registers.mode`) and one new constraint (a paired `check` on shift columns that
  already existed), zero new order-model tables (see M5's Status block). The hedge — M5
  late, M4 early — paid off by not paying off; the risk simply didn't materialize.
