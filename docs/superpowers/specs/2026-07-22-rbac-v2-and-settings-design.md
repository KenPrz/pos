# RBAC v2 and Settings — design

**Date:** 2026-07-22
**Status:** Approved

## Goal

Turn the fixed two-role RBAC into admin-manageable data — role CRUD, per-user direct
permission grants, permission-gated back-office access — and give admins a Settings
surface for the config values the repo has already sanctioned for promotion. Bundle
the authorization bugs the 2026-07-22 audit confirmed.

Grounding: the audit (this conversation, 2026-07-22) verified every state-changing
route is already permission-gated; this work makes the *management* of those
permissions runtime data instead of code, without touching the register tier's
enforcement path.

## Decisions (approved)

1. **Permissions stay code-defined** (`Permissions.php` catalog); **roles get full
   CRUD** as global templates — a role means the same thing at every location.
2. **Back-office access becomes permission-based**: any active user with at least one
   admin-tier permission can sign in and sees only permitted sections; `is_admin`
   remains the all-access flag.
3. **Direct per-user grants are per-location**, like roles (the teams schema already
   supports this via the unused `model_has_permissions` table).
4. **Settings ships the sanctioned set**: business identity (settings table) plus
   per-location `variance_approval_threshold_cents` and `low_stock_threshold`
   columns. Everything else stays engineer config per `04-backend-conventions.md`.

## Not in scope (recorded for later)

- Admin/back-office stock operations UI (receive/adjust/count stay register-tier).
- `variant_location_prices` write path (ghost feature stays read-only).
- `product_variants.position` (dead column stays dead).
- Per-location role *meanings* (a template is global by design).
- Decomposing `is_admin` into granular super-permissions.
- Promoting currency, TTLs, rate limits, order-number format (documented decisions).

## Permission catalog changes

Three new permissions (code, `Permissions.php`, provisioned by `provisionGlobal()`):

| Permission | Gates | Default role |
| --- | --- | --- |
| `report.stock.view` | `GET /admin/reports/stock` (fixing the audit's mis-gate on `report.sales.view`) | supervisor |
| `settings.manage` | the Settings section / settings routes | none (admin-only until granted) |
| `role.manage` | role-template CRUD routes | none (admin-only until granted) |

Admin-tier permission set (drives back-office access): `catalog.manage`,
`user.manage`, `location.manage`, `register.enroll`, `audit.view`,
`report.sales.view`, `report.stock.view`, `settings.manage`, `role.manage`.

## Role templates

**Schema.** `role_templates` (uuid pk, `name` unique, `is_system` bool, timestamps) +
`role_template_permissions` (template_id, permission_id, composite pk). Seeder
creates `cashier` and `supervisor` as `is_system` templates with their current
permission sets from `Permissions::cashier()/supervisor()` (which remain the seed
source, no longer the runtime truth).

**Sync model.** Spatie's per-location role rows become materialized copies of
templates. `RoleProvisioner` is rewritten around templates:

- `provisionForLocation(Location)` — for every template, firstOrCreate the spatie
  role row `(name, location_id)` and `syncPermissions` from the template.
- `syncTemplate(RoleTemplate)` — after any template create/edit/rename, sync its row
  at every active location (rename updates the spatie rows' names; permission edits
  re-sync).
- **`CreateLocation` calls `provisionForLocation`** — closing the audit's confirmed
  bug (UI-created locations currently 500 on role assignment).

**Rules.** `is_system` templates: permission sets editable, rename/delete forbidden.
Custom templates: delete only when no `model_has_roles` row references any of the
template's location rows (409-style domain error otherwise). No archive concept —
roles are structural, not financial.

**API.** `GET /admin/roles`, `POST /admin/roles`, `PATCH /admin/roles/{id}` (name for
custom templates, permission list for all), and `POST /admin/roles/{id}/delete` for
removing an unassigned custom template — a POST, not a `DELETE` verb, keeping the
repo's "no DELETE route anywhere under /admin" invariant literal. All gated
`role.manage`. Audited like every admin write.

**User assignment.** `roles[]` on user create/update validates against
`role_templates.name` instead of the hardcoded pair; `RoleAssignments::sync()`
unchanged in mechanism (direct `model_has_roles` writes, provisioning check stays
and now fails only for genuinely unprovisioned locations).

## Direct per-user permissions

`permissions[]` on user create/update: `[{location_id, permission}]`, full-set
replace, validated against `Permissions::all()`. New domain class
`PermissionAssignments` mirroring `RoleAssignments`: reads/writes
`model_has_permissions` directly (never spatie relations — team-scope gotcha),
ordered so intermediate states satisfy constraints. Spatie unions direct grants with
role permissions at `can()` time under the team context `EnsureStaffSession` already
sets — the register tier needs zero changes. `AdminUserResource` exposes both lists.

## Back-office access

**Middleware.** `EnsureAdmin` → `EnsureBackOffice`: bearer token (admin-login
issued), user `is_active`, and (`is_admin` OR holds ≥1 admin-tier permission at any
location — role-derived or direct). Route alias `admin` repointed; register tier
untouched.

**Authorization rule.** Admin-tier surfaces are global, so per-route checks use
**"holds it anywhere"**: a new `AdminAccess` domain service
(`holdsAnywhere(User, string $permission): bool`,
`locationsWhere(User, string $permission): array`) doing direct joins over
`model_has_roles`/`model_has_permissions` + role/permission tables. Every admin
FormRequest `authorize()` switches from Gate-bypass reliance to
`is_admin || AdminAccess::holdsAnywhere(...)`. `Gate::before` for `is_admin` stays
(register tier + belt-and-braces).

**Reports exception.** The sales/stock report requests additionally validate the
requested `location_id` against `locationsWhere(user, report.*.view)` (admins: all
locations). The UI's location picker filters the same way.

**Login + session.** `POST /admin/login` accepts any user passing the middleware
bar (rejects otherwise with the existing invalid-credentials envelope — no
user-enumeration hints). `AdminSessionResource` gains `sections`: the effective
admin-tier permission list (union across locations, `is_admin` ⇒ all). The
back-office sidebar renders only permitted sections; direct navigation to a
forbidden section shows the existing empty-state pattern, and the API refuses
regardless. Today is visible to every back-office user; its widgets render only
for permissions the user holds (sales tiles need `report.sales.view`, the
low-stock tile `report.stock.view`, the audit strip `audit.view`).

## Settings

**Business identity.** New `settings` table (`key` text pk, `value` jsonb,
timestamps) + code-side registry of known keys: `business.name`,
`business.address`, `business.tax_id`. Domain reader `Settings::get(key)` returns
DB value ?? config fallback (`pos.business.*` env values keep working — existing
deploys unaffected; the boot-time `required` check keeps demanding the env
fallback for `business.name`). `ReceiptResource` reads through it. API:
`GET /admin/settings`, `PATCH /admin/settings` (registry-validated keys only),
gated `settings.manage`, audited. UI: new Settings sidebar section.

**Per-location thresholds.** Nullable columns on `locations`:
`variance_approval_threshold_cents` (int), `low_stock_threshold` (numeric matching
stock qty scale). Null ⇒ config default. Consumers change:
`ApproveVariance`/`CloseShiftResource` and `StockReport` read
`location->x ?? config(...)`. Location create/update requests + `LocationEditor`
gain both fields (blank = default). The config keys and their comments stay (they
are now the documented fallback).

## Quick fixes bundled

1. **Stock report re-gated** to `report.stock.view` (see catalog changes).
2. **`requires_supervisor` enforced**: `ApplyDiscount` authorizes
   `order.discount.apply` when the discount's flag is true; when false,
   `order.line.add` suffices (cashier-safe discounts become real). Route-level gate
   moves accordingly (into the action or request with the discount loaded — design
   detail for the plan; behavior is the contract). `RemoveDiscount` unchanged
   (supervisor-tier).
3. **`de9bc07` consistency sweep**: Makefile e2e target's hardcoded
   `POS_ADMIN_PASSWORD=admin-dev-password` → `password` (currently breaks
   `make e2e`); `POS_SEED_CATALOGS ?= grocery` → `restaurant` with its
   mirror-comment true again; CLAUDE.md (×2), `docs/06-roadmap.md`, both
   `.env.example`s aligned to the `restaurant` default;
   `docs/user-manual/capture_screenshots.mjs` ADMIN password constant updated.

## Register-tier impact

None by design: `EnsureStaffSession` team context, `can()` checks, and all
FormRequests are untouched; templates materialize into the same spatie rows the
register already reads. The one register-visible behavior change is deliberate:
cashiers can apply `requires_supervisor=false` discounts (the register UI's
Discount panel visibility should key on "any application possible" — plan decides
the minimal UI adjustment; acceptable to leave the panel supervisor-only in v1 if
the API behavior is correct, but then note it).

## Error handling

- Template rename/delete violations → domain exceptions mapped to the standard
  error envelope (409/422 style, matching existing patterns).
- Unknown permission names in grants/templates → 422 validation.
- Settings writes to unregistered keys → 422.
- Non-admin without any admin-tier permission logging into BO → same 401 envelope
  as bad credentials.

## Testing / acceptance

- Backend (Pest, real Postgres): template CRUD incl. sync-on-edit and
  provision-on-location-create (regression for the audit bug); direct grants incl.
  union-with-role at the register tier (a cashier granted `order.discount.apply` at
  one location can void there and not elsewhere); BO access matrix (non-admin with
  one section, denied sections, reports location filtering, is_admin unchanged);
  settings fallback ordering; per-location threshold overrides (variance approval +
  low-stock) with null-falls-back; discount-flag enforcement both ways. Existing
  suites stay green (baseline 490 + manual-branch additions).
- Back office (vitest): roles editor, user editor grants UI, settings section,
  sidebar permission gating, reports picker filtering.
- `make e2e` green end-to-end — also proves the password fix.
- Docs: `docs/05-rbac.md` rewritten for v2 (template model, direct grants, BO
  access rule, is_admin unchanged), `docs/03-api.md` new endpoints,
  `docs/02-data-model.md` new tables/columns, roadmap + CLAUDE.md records, user
  manual chapters 10/11 updated where they describe roles ("two fixed roles" is no
  longer true) — plus the manual's CI rebuild will fire.

## Success criteria

An admin can: create a "shift-lead" role with a custom permission set and assign
it; grant a single user `report.sales.view` at one location and have that user log
into the back office seeing only Reports scoped to that location; edit business
name/address/tax-id in Settings and watch receipts change without a deploy; set a
per-location variance threshold; mark a discount cashier-safe and see a cashier
apply it. All with every suite and `make e2e` green.
