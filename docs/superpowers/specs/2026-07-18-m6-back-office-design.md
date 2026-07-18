# M6 — Back office: design

Owner-approved scope: the roadmap's three bullets (catalog CRUD + user management +
location/register settings; sales/stock reports; audit log viewer), delivered as a
**separate Next.js app** with **admin-only email+password auth** and **tables + CSV
reports** — the owner's three shaping calls. Industry calibration (Square Dashboard,
Lightspeed Back Office) informed the conventions; sources at the bottom.

**Done when: an admin never needs `psql`.**

## Decisions (owner-approved)

| Decision | Choice | Why |
| --- | --- | --- |
| App shape | Separate app, `frontend/back-office`, port 5175, own `/api` rewrite | The till is a locked-down kiosk surface; the back office is a laptop web app. Separation keeps admin code off tills and till state machines out of admin. Cost: `money.ts`, DESIGN tokens, and the envelope unwrapper are **copied, not shared** (no monorepo tooling for three files — deliberate duplication, noted here so nobody "fixes" it into a package prematurely). The admin app gets its own API client; the register's `api.ts` is not shared. |
| Auth | `POST /admin/login` email+password → Sanctum bearer token; logout revokes | Lights up the M2 schema (`users.email`/`password_hash` + the either-email-or-pin CHECK; `User::getAuthPassword` already maps). Same bearer-token style the register uses — one auth pattern in the codebase. Login throttled like PIN login. No password reset in v1 (admins reset each other). |
| Access | **Admin-only**: login refuses `is_admin = false` | Supervisor permissions are per-location spatie teams; the back office is location-less, and per-request team-context juggling is the documented silently-wrong-answers trap. Admins bypass teams via `Gate::before`. Endpoints still declare their permissions (`catalog.manage`, `user.manage`, `location.manage`, `register.enroll`, `audit.view`, `report.sales.view`) — an admin-only endpoint must still name what it requires (docs/05-rbac.md). |
| Reports depth | Date-ranged tables + client-side CSV | Covers "never needs psql"; charts wait for someone to ask. CSV generated in the browser from the same JSON the table renders — no server CSV path. |

## Backend surface

All of it follows the standing conventions: one action = one route = one single-action
controller; FormRequest validates + authorizes + maps; every mutation audited; money in
integer cents; validation failures 400, domain refusals 409/422 with one exception class
per code. New routes live under `/api/v1/admin/*` behind `auth:sanctum` + an
`EnsureAdmin` middleware (token user must be `is_admin`).

### Auth

- `POST /api/v1/admin/login` `{email, password}` → `{token, user}` (throttled; audit `admin.login`)
- `POST /api/v1/admin/logout` — revokes the presented token

### Catalog CRUD

`GET|POST|PATCH /api/v1/admin/{products|variants|categories|modifier-groups|modifiers|tax-rates|discounts}`
(+ `GET .../{id}`). Conventions with teeth:

- **Archive, never delete.** No DELETE routes exist. Every entity deactivates
  (`is_active` or equivalent); deactivated items leave the register's catalog payload
  but stay resolvable for receipts, refunds, and reports. Order-line snapshots are why:
  history references these rows forever.
- **Price/tax changes are future-only, automatically** — lines snapshot at add time
  (M2 discipline); no effective-dating machinery.
- **Register freshness**: the till's catalog query has a 5-minute staleTime; that is the
  propagation SLA. `GET /catalog?updated_since=` stays unimplemented (revive when a real
  catalog is big enough that full refetches hurt).
- Modifier groups edit `min_select`/`max_select` under the existing CHECK; attach/detach
  to products via the pivot. Discount CRUD covers the catalog kinds (percent/fixed,
  order/line scope, `requires_supervisor` flag stored as-is — see Deferred).

### User management

- `GET|POST|PATCH /api/v1/admin/users` (+ `{id}`): create staff (name + PIN via the
  existing `SetStaffPin` action — keeps the HMAC `pin_lookup` and the PIN-collision
  check), set/change email+password (promotes to back-office login), toggle
  `is_active`, set `is_admin`.
- Role assignment per location writes **`model_has_roles` directly** — never the spatie
  `roles()` relation (the twice-bitten gotcha, docs/05-rbac.md).
- **Deactivate, never delete**: history (sales, shifts, audit rows) survives the person
  (industry standard — Lightspeed keeps departed staff in reports).
- Roles stay seeded and uneditable: cashier / supervisor / `is_admin`. Custom permission
  sets are a non-goal (v1 policy, docs/05-rbac.md).
- Guard: an admin cannot deactivate or de-admin **themselves** (422) — the lockout
  footgun.

### Locations & registers

- `GET|POST|PATCH /api/v1/admin/locations` (+ `{id}`): name, timezone,
  `prices_include_tax`, receipt header/footer. (Orders snapshot `prices_include_tax` at
  open, so flips never rewrite in-flight arithmetic — already guaranteed.)
- `GET|POST|PATCH /api/v1/admin/registers` (+ `{id}`): name, `is_active`, **`mode`**
  (`retail|food`) — closes M5's psql hand-off.
- **Enrollment UI**: button on a register row calls the existing
  `POST /api/v1/registers/enroll` (admin bearer token — the M2 route finally reachable
  end-to-end), shows the device token **once** for pasting into the till's setup screen.

### Reports

- `GET /api/v1/admin/reports/sales?from=&to=&location_id=&group_by=day|category|user`
  — computed off the **payments/refunds ledgers** at request time (no aggregation
  tables at this scale), grouped by `business_date`. Voided orders excluded by status —
  which excludes split originals, so splits never double-count. Category attribution via
  the line's `variant_id` → live product category (reporting joins are allowed to see
  the live catalog; receipts are not).
- `GET /api/v1/admin/reports/stock?location_id=&low_only=` — reads `stock_levels`;
  low = at/below a single config threshold (`pos.stock.low_threshold`, engineer-deployed
  per the config-vs-database rule). A per-variant reorder point is a schema change —
  deferred until a store asks for one.
- The by-user grouping doubles as the discount/refund fraud lens (who comps most), with
  drill-down via the audit viewer.

### Audit viewer

- `GET /api/v1/admin/audit?entity_type=&entity_id=&user_id=&action=&from=&to=` —
  paginated, newest-first, read-only over the append-only `audit_log` that every
  mutation has fed since M2. Pure read side; zero write-side work.

### M5-ledger fold-ins

- `GET /api/v1/registers/open-shifts` (staff tier): registers at the location with an
  open shift — fixes the register transfer-picker's discovery (it currently infers
  targets from open orders and misses tabless registers).
- Z-report: split originals (`void_reason like 'split into%'`) reported separately from
  genuine voids, so supervisors stop reading splits as mistakes.
- Cleanups: consolidate `Order::opener()`/`openedBy()` into one relation; rename the
  shared `{order}` envelope resource out of `VoidOrderResource` into a neutral name.

## Back-office frontend

Next.js 16 app router + React Query + TS7, mirroring the register app's structure
(one client boundary under a server shell; localStorage token after mount; typecheck via
`tsc --noEmit` with Next's checker disabled — the TS7 constraint applies here too).

- **Login screen** → **shell**: carbon bar + nav (Catalog / Users / Locations &
  Registers / Reports / Audit).
- DESIGN.md console-chrome language adapted **laptop-first**: plates, bevels, uppercase
  labels, warm color = one primary action per screen; denser tables; no 44px touch
  mandate (mouse surface).
- Location picker on location-scoped screens (reports, stock, role assignment).
- Forms are plate-cards with explicit SAVE; archive/deactivate behind a confirm.
- CSV export = client-side serialization of the rendered report data.
- 401 anywhere → token cleared → login (same convention as the register).

## Testing / done-when

- Pest per action at the M3–M5 bar: auth (throttle, non-admin refusal, revocation),
  CRUD validation matrices, the self-lockout guard, report sums proven against seeded
  ledger fixtures to the cent, audit filters, open-shifts endpoint, Z-report split
  annotation.
- Back-office vitest: login flow, a CRUD form, report table + CSV serialization, using
  the register app's harness idioms.
- **E2e "admin day"** (committed, tokens via env like the M4/M5 scripts): login → build
  category/product/variant/modifier-group from nothing → hire a cashier (PIN + role) →
  set a register to food mode → enroll it → **register-side**: sale of the new product
  with a modifier (catalog→till round trip) → **admin-side**: reprice the product,
  verify the pre-change receipt reprints identically, sales report matches the ledger
  to the cent, and every action above appears in the audit viewer.
- Suites green: backend (397 baseline), register app (79 baseline), new back-office
  gates.

## Deferred, with revival triggers

| Deferred | Revive when |
| --- | --- |
| `requires_supervisor` relaxation (cashiers applying marked-safe discounts) | Owner decides cashiers may discount alone — today's behavior is *stricter* than the flag; loosening the fraud surface is a product call, not a milestone rider. |
| Ad-hoc discounts + resolver approved/resolved column split | A real comp workflow needs type-an-amount at the till. One feature; the M5 ratchet note (`.superpowers/sdd/progress-m5.md`) rides with it. |
| Supervisor/bookkeeper back-office access | The first accountant. Requires deliberate per-location team-context handling. |
| CSV **import** (bulk catalog) | A migration from another POS, or a catalog too big to type. |
| Charts / dashboard landing | Someone asks after living with the tables. |
| `updated_since` catalog deltas | Catalog size makes full refetches hurt. |
| Password reset flow | The first locked-out solo admin. |

## Sources (industry calibration)

- [Square Dashboard features](https://squareup.com/us/en/point-of-sale/features/dashboard) — item library CRUD, team permissions, multi-location pricing
- [Lightspeed: adding Back Office users](https://o-series-support.lightspeedhq.com/hc/en-us/articles/31329358221595-Adding-users-in-Back-Office-Staff-Logins) — per-staff logins for the audit trail; deleted users' history survives
- [Lightspeed: user roles](https://o-series-support.lightspeedhq.com/hc/en-us/articles/35765184351387-Managing-staff-access-with-user-roles) — read-only bookkeeper role (our named deferral)
- [Lightspeed: Back Office reports](https://o-series-support.lightspeedhq.com/hc/en-us/articles/31329355994523-Accessing-Back-Office-Reports) — sales summary by day/user; refund/discount audit reports as fraud instruments
