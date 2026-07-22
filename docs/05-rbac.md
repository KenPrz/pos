# RBAC

`spatie/laravel-permission` **8.3.0**. Verified on 2026-07-15 to resolve cleanly against
Laravel 13.20 / PHP 8.5 (`composer update --dry-run` on the real constraint set, not the
package's README).

## Why the package

We could hand-roll three roles behind a `role` enum — and the original schema in
`02-data-model.md` did exactly that. It's replaced because the enum answers the wrong
question. `role = 'supervisor'` forces every call site to ask *who someone is* and infer
what they may do. Permissions invert it: call sites ask **`can('order.discount.apply')`**,
which is the actual question, and the role→permission mapping moves to one seeder.

That inversion is what makes "supervisors may now void lines" a data change instead of a
grep through every `authorize()`.

## The big decision: roles are scoped per location

**`teams` is enabled, with `team_foreign_key = location_id`.**

A role assignment is therefore `(user, role, location)` rather than `(user, role)`. Maria
can be a cashier at Downtown and a supervisor at Airport, and her supervisor powers do
**not** follow her to Downtown.

This matters because the alternative has a real fraud hole. `01-architecture.md` puts the
supervisor boundary exactly on the actions that let someone remove money without a
customer noticing — discounts, voids, no-sale. Global roles would mean a promotion at one
store silently grants comp powers at every other store, which is precisely the thing that
boundary exists to prevent.

Normally spatie's teams feature is awkward, because the app must decide "which team is
this request about?" and users switch between them. **Our architecture eliminates that
question.** A register is bound to a location (`registers.location_id`), and the device
token identifies the register. So the team context is never ambiguous or user-supplied —
it's a property of the physical terminal the request came from. We get per-location roles
almost free, which is why the complexity is worth it here and often isn't elsewhere.

Consequence: **`user_locations` is deleted.** Holding a role at a location *is* working at
that location; a separate pivot would be a second, disagreeing source of truth. "Which
locations does Maria work at?" is `select distinct location_id from model_has_roles where
model_id = ?`.

`admin` is **not a spatie role at all** — see the correction below.

### Correction: admin cannot be a team-scoped role

This document originally claimed admin would be "global, assigned with a null team key".
**That was wrong**, and building it proved so. The claim was flagged here as needing an
assertion rather than trust; it needed one, and failed it.

Reading `HasRoles::roles()` in the installed package:

```php
->wherePivot($teamsKey, getPermissionsTeamId())               // pivot MUST equal current team
->where(fn ($q) => $q->whereNull($teamField)->orWhere(...))   // role DEFINITION may be null
```

A null team key makes a role **definition** shared across teams. It does not make an
**assignment** span them: `model_has_roles.location_id` is part of that table's primary
key, so it is `NOT NULL`, and every assignment pins to exactly one location. Assigning a
global role with no team context fails outright:

```
null value in column "location_id" of relation "model_has_roles" violates not-null constraint
```

So there are only two honest options, and neither is a global role:

1. **Assign admin at every location.** Then opening a store silently locks every admin
   out until someone provisions it — a footgun with no error message.
2. **Take admin out of spatie.** A `users.is_admin` flag, granted via `Gate::before`.

We do (2). It is also what spatie's own documentation recommends for a super-admin:

```php
// AppServiceProvider::boot()
Gate::before(fn (User $user): ?bool => $user->is_admin ? true : null);
```

Returning `null` rather than `false` is essential — `false` denies everyone else outright
instead of letting the normal checks run.

**This does not resurrect the `role` column we deliberately removed.** That column was
removed because `role === 'supervisor'` forces call sites to ask *who someone is* and
infer what they may do. Call sites still ask `can('order.void')`; the flag only
short-circuits the gate. The one capability that is genuinely global is the one that
cannot be modelled per-location — which is a coherent line, not an exception.

Consequence worth knowing: `catalog.manage`, `user.manage`, `location.manage`,
`register.enroll`, `audit.view`, `settings.manage`, and `role.manage` are granted by
**no role**. That is correct — only admins do those things by default, and admins
bypass. The permission names still exist because the endpoints still name what they
require, and (RBAC v2, below) an admin can hand any one of them out as a direct
per-location grant without inventing a role for it.

## Verified integration notes

The published migrations **do not work with our schema as shipped**. This is not a
surprise to discover during M2; it's a known, bounded edit list. All four were confirmed
by reading the installed package source, not the docs.

`spatie/laravel-permission` assumes integer keys throughout:

| Stub column | Ships as | Must become | Because |
| --- | --- | --- | --- |
| `roles.location_id` | `unsignedBigInteger` nullable | `uuid` nullable | `locations.id` is `uuidv7()` |
| `model_has_roles.location_id` | `unsignedBigInteger` | `uuid` | same |
| `model_has_permissions.location_id` | `unsignedBigInteger` | `uuid` | same |
| `model_has_*.model_id` | `unsignedBigInteger` | `uuid` | `users.id` is `uuidv7()` |

We publish the migrations, so we own them and these edits are ours to keep. While in
there, add the FK the stub omits (`roles.location_id → locations(id)`); it only creates
indexes. Keep the migration **config-driven** rather than hardcoding the column names —
the package reads the same config at runtime to build its queries, and the two must not
be able to drift.

**Sanctum has the identical problem**, and it isn't in the package's docs either:
`create_personal_access_tokens_table` uses `$table->morphs('tokenable')`, which is a
bigint. Registers are `uuidv7`, so it must be `uuidMorphs`. Left alone, enrolling a
device fails at insert with an error that reads like a Sanctum bug.

*(An earlier draft of this table claimed the pivot columns ship with `default '1'`. They
do in `add_teams_fields.php.stub` — the migration for retrofitting teams onto an existing
install — but not in `create_permission_tables.php.stub`, which is the one we publish.)*

**`roles` and `permissions` keep their `$table->id()` bigint PKs.** This is a deliberate
exception to the UUID convention in `02-data-model.md`, not an oversight: they are
seeded reference data, not domain records. They are never client-visible, never
concurrent-written, and never sorted by creation time — the reasons we chose uuidv7
simply don't apply. Fighting the package to make them UUIDs would be cost with no benefit.

Config:

```php
// config/permission.php
'teams' => true,
'column_names' => [
    'team_foreign_key' => 'location_id',
    'model_morph_key'  => 'model_id',   // name kept; only the column TYPE changes to uuid
],
```

## Permission catalog

Named `resource.action`, matching the `can('order.line.add')` call in
`04-backend-conventions.md`. This list and `03-api.md` are the same list — an endpoint
with no permission is a bug in one of the two documents.

**Orders**

| Permission | Gates |
| --- | --- |
| `order.open` | Start an order / tab |
| `order.line.add` | Add a line |
| `order.line.update` | Change quantity |
| `order.line.void` | Remove a line — **money leaves** |
| `order.discount.apply` | Apply a discount — **money leaves** |
| `order.void` | Void a whole order — **money leaves** |
| `order.reopen` | Reopen a closed order — **money leaves** |
| `order.transfer` | Hand a tab to another server's shift |

`order.line.update` covers a quantity change either direction, with one carve-out:
**decreasing the quantity of a line already fired to the kitchen** (`prep_state` in
`in_progress` or `ready`) needs `order.line.void` too — shrinking a sent line is the same
fraud surface as voiding one, so it takes the same permission rather than a new one.
Increasing a fired line's quantity is not gated this way; a kitchen wanting more of
something isn't a fraud path. The prep verb itself (`PATCH .../prep`) is gated on
`order.line.update`, but **downgrading a line out of a fired state** (`in_progress` or
`ready`) back toward an earlier one needs `order.line.void` too — un-firing a line on
paper is the front half of the same shrink-past-the-gate fraud path, so it takes the same
permission. Moving a line forward through the states is ungated beyond `order.line.update`.
This is decided inside the action, not by the route's
`can()` — the flat permission can't express "only when decreasing and only when fired,"
which is the same shape as the ownership checks in Permissions vs Policies below, just
resolved in the action rather than a policy class.

**Payments and refunds**

| Permission | Gates |
| --- | --- |
| `payment.take` | Take a tender |
| `payment.void` | Void a payment — **money leaves** |
| `refund.create` | Refund — **money leaves** |

**Shifts and drawer**

| Permission | Gates |
| --- | --- |
| `shift.open` | Open a drawer with a float |
| `shift.close` | Count and close |
| `shift.cash_movement` | Payout / paid-in / drop — **money leaves** |
| `shift.approve_variance` | Approve a variance over threshold |
| `drawer.no_sale` | Open the drawer with no sale — **money leaves** |

**Catalog and admin**

| Permission | Gates |
| --- | --- |
| `catalog.view` | Read the menu (register needs this) |
| `catalog.manage` | Products, variants, modifiers, tax rates, discounts |
| `user.manage` | Staff and their roles |
| `location.manage` | Locations, settings |
| `register.enroll` | Enroll a terminal |
| `settings.manage` | Business identity + per-location thresholds (RBAC v2) |
| `role.manage` | Role-template CRUD (RBAC v2) |

**Stock**

| Permission | Gates |
| --- | --- |
| `stock.adjust` | Manual adjustment (shrinkage, damage, correction) — **money leaves** |
| `stock.receive` | Record incoming stock |
| `stock.count` | Record a physical count |
| `stock.movements.view` | Stock movement history |

All four are `supervisor`. Adjustments are how shrinkage gets hidden — a unit walked off
the books without a supervisor's sign-off is functionally the same theft as a till void
nobody signed off on, and receiving/counting are the levers that make an adjustment
invisible if they aren't held to the same standard.

**Reports**

| Permission | Gates |
| --- | --- |
| `report.z.view` | Z-report for a shift |
| `report.sales.view` | Sales reports across shifts |
| `report.stock.view` | The stock/low-stock report |
| `audit.view` | The audit log |

`report.stock.view` is new in RBAC v2 — a 2026-07-22 audit found `GET
/admin/reports/stock` mis-gated on `report.sales.view` (a user who can read sales
figures is not necessarily who should read inventory counts, and vice versa). It's
its own permission now, granted to `supervisor` alongside `report.sales.view`, and
the stock-report `FormRequest` checks it explicitly rather than reusing the sales
one.

Every permission marked **money leaves** is supervisor-or-above. That set is not a
coincidence or a judgement call — it is the fraud surface from `01-architecture.md`,
enumerated. The label covers value leaving the *business*, not only cash leaving a
drawer — a stock adjustment moves sellable inventory out of the count the same way a void
moves cash out of the till, which is why `stock.adjust` carries the label too. When adding
a permission, the question that decides its role is "can this be used to take value out of
the business without a customer noticing?"

## Roles (RBAC v2: admin-editable templates)

Through M6, roles were seeded and fixed — two names, hardcoded permission sets, no way
to add a third without a migration. **RBAC v2 turns a role into data**: a
`role_templates` row (`02-data-model.md`) is a name plus a permission set, editable at
runtime through `/admin/roles*`. The permission *catalog* stays code (the list above) —
what changes is which permissions a given role name grants, and how many role names
exist.

**The materialization problem, and why templates exist at all.** Spatie's teams feature
makes a `Role` row per-team: `Role::create(['name' => 'cashier'])` creates a cashier for
*one* location, and a template with no per-location counterpart is a name nobody can
actually be assigned. So a `RoleTemplate` is the single source of truth, and
`RoleProvisioner` keeps a materialized spatie `Role` row in sync at every location:

- `provisionGlobal()` — seeds the permission catalog once, and seeds exactly two
  **system templates**, `cashier` and `supervisor`, with the same permission sets this
  document always specified (`Permissions::cashier()`/`supervisor()`, still the seed
  source — the *template row* is the runtime truth after that first seed; a reseed never
  clobbers an admin's edit to it).
- `provisionForLocation($location)` — for every template that exists, materializes its
  spatie `Role` row at that location. Called from `CreateLocation`, so a store opened at
  runtime gets every current role, not just the two system ones.
- `syncTemplate($template)` — after any template create/edit/rename, re-materializes it
  (permissions synced, or the spatie row renamed) at **every** location in one pass.

**System vs custom.** `is_system` (`cashier`, `supervisor`) may have its **permission
set** edited but not its name — renaming or deleting either would strand every seed,
script, and doc that assumes they exist under those exact names
(`RoleTemplateIsSystem`, 422). A **custom** template (`shift-lead`, `bookkeeper`,
whatever a business invents) can be renamed or deleted freely, with one guard: delete is
refused while any materialized `Role` row for it still has an assignment
(`RoleTemplateInUse`, 422, with the assigned-user count in `details`) — unassign
everywhere first, the same "no dangling reference" shape as archive-never-delete
elsewhere in this system, except a role template genuinely has nothing left pointing at
it once unassigned, so it's a real delete, not an archive (`role_templates` has no
`is_active` column to archive into).

`admin` is still **not a template at all** — `users.is_admin` + `Gate::before`, per the
correction above. Templates are how a business shapes *its own* roles; the one
capability that is genuinely global still can't be modelled per-location, so it stays
outside the system entirely.

Endpoints (`03-api.md`): `GET/POST /admin/roles`, `PATCH /admin/roles/{id}`,
`POST /admin/roles/{id}/delete` (a `POST`, not a `DELETE` verb — the repo's "no `DELETE`
route anywhere under `/admin/*`" rule is about the HTTP verb, and a role-template row
really is deleted, so the URL still can't use it), and `GET /admin/permissions` (the
catalog above, grouped for the role editor and the user-management role picker). All
four gated `role.manage`; `GET /admin/permissions` also accepts `user.manage`, since the
user editor's role/grant pickers need the same catalog and shouldn't require
role-editing rights just to read it.

A cashier can still open and close their own drawer without a supervisor, because
requiring one for a routine open would mean either a manager tied to the terminal all
morning or a manager's PIN written on a sticky note — and the second is what actually
happens. Variance *approval* is where the supervisor belongs; that's the moment worth
their time. Nothing about that changed — it's still true of the `cashier`/`supervisor`
templates' default permission sets, just expressed as editable data now instead of a
hardcoded pair.

## Direct per-location permission grants (RBAC v2)

A role assigns a *bundle*; sometimes what's needed is one permission for one person at
one location — "Maria can pull the sales report at Airport" without inventing a
`report-only` role for a bundle of one. `users.permissions[]` (`03-api.md`) is exactly
that: `[{location_id, permission}]` rows in spatie's own `model_has_permissions` table,
which teams already gave us and RBAC v1 simply never wrote to.

**Same gotcha as roles, same fix.** `PermissionAssignments` reads and writes
`model_has_permissions` with direct table joins, never spatie's `permissions()`
relation — that relation applies `wherePivot(location_id, currentTeam)` exactly the way
`roles()` does, so it can only ever answer "direct grants at the location I'm already
standing at." The CLAUDE.md gotcha about `roles()` was written for that relation, but
`permissions()` is generated by the same package the same way, and it bit the same way
during this work.

**Full-set replace**, mirroring `roles[]`: sending `permissions` on a user create/update
replaces every existing direct grant for that user; omitting the key leaves them
untouched; sending `[]` clears them all. Validated against the same permission catalog
as templates.

**Union at `can()` time, no register-tier change.** A direct grant and a role-derived
permission both land in `model_has_roles`/`model_has_permissions` under the same team
context `EnsureStaffSession` already sets, and spatie's own `can()` unions both sources
when teams are enabled — so a cashier granted `order.discount.apply` directly at one
location can apply discounts there, and nowhere else, without `EnsureStaffSession`,
`ApplyDiscount`, or any other register-tier code changing at all. The register was
already asking the right question (`can('order.discount.apply')`); RBAC v2 just adds a
second way to answer yes.

## Back-office access (RBAC v2: permission-based, not admin-only)

Through M6, `POST /api/v1/admin/login` was **admin-only** — there was no tier that
could reach `/admin/*` while stopping short of full admin, and the deferred table named
the trigger: "the first accountant who needs sales and audit visibility without
order-void or user-management power." RBAC v2 is that trigger being pulled.

**The rule is "holds it anywhere."** Admin-tier surfaces are global — there is no
register to read a location off, unlike every other tier in this system — so access is
granted the moment a user holds at least one **admin-tier permission** at *any*
location, via a role or a direct grant. `App\Domain\Rbac\AdminAccess::SECTIONS` is the
admin-tier set: `catalog.manage`, `user.manage`, `location.manage`, `register.enroll`,
`audit.view`, `report.sales.view`, `report.stock.view`, `settings.manage`,
`role.manage`. `holdsAnywhere($user, $permission)` is `is_admin || in_array($permission,
$this->allHeld($user))`, where `allHeld()` is the union of every role-derived and direct
permission across every location — direct table joins on `model_has_roles` and
`model_has_permissions`, for the same reason `PermissionAssignments` and
`RoleAssignments` are direct joins: spatie's relations answer "at the team I'm
standing at," and there is no team to stand at here.

**Every admin `FormRequest::authorize()` calls `AdminAccess::holdsAnywhere()` (via the
`AuthorizesBackOffice` trait's `allowsBackOffice()`), never a bare `can()`.** A bare
`can()` reads the *current* permission team context, and an admin request has none set
— `EnsureStaffSession` is what sets it, and admin requests never run through that
middleware. Calling `can()` in an admin `FormRequest` doesn't error; it silently checks
against whatever team context (usually none) happens to be set, which is the same
failure shape the `roles()`/`permissions()` gotcha is, one layer up. This is now a
CLAUDE.md gotcha in its own right.

**The back-office gate is two checks, not one, and both matter for different reasons.**
`EnsureBackOffice` (the `admin` route middleware, replacing the old `EnsureAdmin`)
requires the bearer token's owner to be `is_active` **and** hold at least one admin-tier
permission (`holdsAnyAdminSection`) **and** for the token itself to carry the `admin`
Sanctum ability. That third check earns its place now, not before: a register staff
session token (`StaffLogin`'s `register:{id}` ability) authenticates as the same
underlying `User` row, and once ordinary role permissions like a supervisor's default
`report.sales.view`/`report.stock.view` can open admin-tier sections, the
permission check alone would let a staff token that happens to belong to a supervisor
walk straight into the back office. `AdminLogin` mints tokens with the `admin` ability;
device tokens carry `['device']` and staff tokens carry `register:{id}` — neither
satisfies `can('admin')`. The permission check still matters on its own: it's what scopes
an admin-login token to the sections its holder actually holds, not just proof of where
the token came from.

**Session shape.** `AdminSessionResource` (`03-api.md`) carries `sections` — the
admin-tier permissions this user holds, in canonical order, `is_admin` ⇒ every section —
so the back-office sidebar renders only what its holder may open; the API refuses the
rest regardless of what the client tries to render. It also carries
`report_location_ids`: `null` for an admin (every location), otherwise the union of
every location where `report.sales.view` or `report.stock.view` is held — the location
switcher filters down to that set, since a stock-only grant at one store must not leak
a picker option for a store its holder can't actually query.

**Reports stay location-scoped even though back-office login is "anywhere."** Holding
`report.stock.view` *somewhere* is what gets you in the door; it is not a blank check to
read *every* location's stock. `StockReportRequest`/the sales-report request each
additionally validate the requested `location_id` against
`AdminAccess::locationIdsWhere($user, $permission)` (admin: `null`, meaning all) and
throw an `AuthorizationException` if the requested location isn't in that set. This is
the same shape as the ordinary Permissions-vs-Policies split below, just applied to a
permission that happens to be global-*access* but location-*scoped-data*.

`AdminLogin` still refuses wrong email, wrong password, deactivated, and
now-zero-admin-tier-permissions identically (`401 invalid_credentials`) — the same
enumeration-safe shape as before, just with a wider set of users who can pass. It's also
still why `user.manage` guards `self_lockout` (`03-api.md`): with `is_admin` remaining
the only *unconditional* tier and no guaranteed second admin online, an admin who could
revoke their own access would have no one else able to undo it.

**Escalation posture: `user.manage` and `role.manage` are effectively root-equivalent
grants, not ordinary admin-tier permissions.** A `user.manage` holder can set any other
user's `is_admin` flag or hand them any permission grant directly; a `role.manage`
holder can widen the permission set of any role template they themselves already hold,
which reaches every user assigned that template. Neither needs `is_admin` to escalate to
full admin in practice — grant them with the same care as `is_admin` itself, not as a
routine admin-tier permission like `report.sales.view`.

**Archived locations still confer back-office access.** A role or direct grant recorded
at a location that has since been archived is never deleted (archive-never-delete, this
repo-wide), so `AdminAccess::holdsAnywhere`/`allHeld` still see it — a user whose only
admin-tier grant sits at an archived location still logs into the back office and still
sees that section, consistent with every other archived-but-not-deleted row in this
system.

## The `requires_supervisor` discount rule (RBAC v2: enforced, not just stored)

`discounts.requires_supervisor` (`02-data-model.md`) existed since M2 as a column with
no enforcement behind it — any discount could be applied by anyone who could add a line.
RBAC v2 closes that gap, and does it **inside the action**, not the route, for the same
reason `order.line.update`'s fired-line escalation (Permission catalog, above) and
`SetLinePrepState` both live in the action: whether *this* discount needs a supervisor
depends on data (the row's own flag) that isn't known until it's loaded, and a flat
route-level `can()` can't express "only when this particular row says so."

`ApplyDiscountRequest::authorize()` checks only the **floor**: `order.line.add` — any
staffer who can ring up a sale can *attempt* a discount. `ApplyDiscount::execute()`
loads the discount row inside the lock and, if `requires_supervisor` is true, re-checks
`order.discount.apply` against the acting user; failing that check is
`403 discount_needs_supervisor` (`DiscountNeedsSupervisor`), not the generic `forbidden`.
When the flag is false, the floor permission is sufficient — this is what makes a
**cashier-safe discount** a real thing rather than a database column nobody reads:
a "loyalty $1 off" a business marks cashier-safe can now actually be applied by a
cashier, and a discount left at the column's default (`true`) behaves exactly as it
always has.

## Permissions vs Policies

These answer different questions, and conflating them is the standard way RBAC goes
wrong:

- **Permission (spatie): may this person do this *kind* of thing at all?**
  `can('order.void')`
- **Policy (Laravel): may they do it to *this specific record*?**
  `can('void', $order)`

`03-api.md` requires a cashier to close **their own** shift. `shift.close` cannot express
"own" — permissions have no concept of a record. So:

```php
final class ShiftPolicy
{
    public function close(User $user, Shift $shift): bool
    {
        if (! $user->can('shift.close')) {
            return false;
        }

        // Own shift, or a supervisor closing someone else's.
        return $shift->opened_by === $user->id
            || $user->can('shift.approve_variance');
    }
}
```

Rule of thumb: **if the sentence contains "own", "same location", or "already", it's a
Policy.** If it's a flat capability, it's a permission.

Location scoping is the one exception — teams handle it structurally, so a policy never
needs to compare `location_id` by hand. That's a second reason teams earn their keep.
Teams scope *permission checks* only, though: a record *fetch* (`Order::whereKey(...)`
and the like) is not filtered by the team context, so the action itself must still scope
the query to the acting register's location — otherwise a cashier with valid permissions
at their own location can reach another location's row by UUID, and it's a 404 that
leaked into a 200, not a 403.

## Wiring

The team context is set from the register, in middleware, before anything reads a
permission:

```php
final class EnsureStaffSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $register = $request->attributes->get('register');   // set by EnsureDeviceToken
        $staff    = $this->resolveStaffToken($request);       // throws 401 if absent/expired

        // Team context comes from the terminal, never from the client.
        setPermissionsTeamId($register->location_id);

        $request->setUserResolver(fn () => $staff);

        return $next($request);
    }
}
```

`setPermissionsTeamId()` **must run before any permission check or role load**, including
before anything eager-loads `roles`. A stale team context doesn't error — it silently
returns the wrong answer, which for a fraud boundary is the worst possible failure mode.
This gets a dedicated test at M2: the same user, the same PIN, two registers at two
locations, different answers from `can()`.

Two consequences worth stating:

- The team id comes from the **device token's register**, never from a request parameter.
  A client-supplied location would let anyone with a PIN pick their own permissions.
- `03-api.md`'s `403 wrong_location` becomes mostly structural. A cashier at Downtown
  presenting at an Airport register simply has no roles in that team, so `can()` returns
  false. The explicit check remains only for cases where the record's location and the
  register's location disagree (e.g. refunding an order rung up at another store).

## Seeding

**Permissions are seeded from code, never created at runtime; roles (RBAC v2) are
seeded once, then admin-editable data.** `RoleProvisioner` does all of it and is safe
to re-run:

- `provisionGlobal()` — every permission in the catalog above, plus exactly two
  **system role templates** (`cashier`, `supervisor`) seeded `firstOrCreate` from
  `Permissions::cashier()`/`supervisor()`. `firstOrCreate` is load-bearing: after the
  first seed, the `role_templates` table is the runtime truth, and a later reseed must
  never clobber an admin's edit to either template's permission set. It creates no
  admin role/template (see the correction).
- `provisionForLocation($location)` — materializes **every current template**, not just
  the two system ones, into a per-location spatie `Role` row. Called from
  `CreateLocation`, so a store opened at runtime — and any custom role a business has
  since added — is provisioned there too.
- `syncTemplate($template)` — pushes a template's current definition (permission set,
  and a rename) to its materialized row at every location. Called after every
  role-template create/edit/rename; a template with no caller to re-sync it would drift
  from what it was just edited to say.

The point that still surprises people: `Role::create(['name' => 'cashier'])` creates a
cashier for the *current* team only — a role row is per-team with teams enabled,
regardless of whether its definition comes from a seeded template or an admin-created
one. Opening a new store without provisioning it is a store nobody can be assigned
to, and this was a real, confirmed bug before RBAC v2: `CreateLocation` wasn't calling
`provisionForLocation` at all, so a UI-created location silently had no roles.

## Caching

The package caches the permission table aggressively. Two rules:

- The seeder calls `app(PermissionRegistrar::class)->forgetCachedPermissions()` after
  writing. A deploy that seeds new permissions and doesn't flush leaves every terminal
  denying an ability that exists in the database.
- Tests that assign roles must flush between cases, or a passing suite will hide a
  broken permission check.

## Testing

Per `04-backend-conventions.md`, authorization is tested at the action and policy level,
not through HTTP.

The tests that must exist, each corresponding to money walking out the door:

- Every **money leaves** permission is denied to `cashier` and allowed to `supervisor`.
  Table-driven, one case per permission — so adding a permission to the wrong role fails
  CI.
- A supervisor at location A is **not** a supervisor at location B. The teams test.
- A cashier closes their own shift; a cashier cannot close another cashier's; a
  supervisor can.
- `admin` resolves at every location while holding no role anywhere.
- Non-admins are unaffected by the `Gate::before` bypass — it must return `null`, not
  `false`.
- Roles are provisioned for a location created at runtime — the regression test for the
  bug the paragraph above describes.
- **RBAC v2 additions:** a custom role template's create/edit/rename/delete, including
  sync-on-edit reaching every location and delete refused while assigned
  (`role_template_in_use`) or the template is a system one (`role_template_is_system`); a
  direct per-location grant unions with role permissions at `can()` time and is scoped to
  its location (a cashier granted `order.discount.apply` at one location can apply
  discounts there and nowhere else); the back-office access matrix (a non-admin with one
  admin-tier permission gets in and sees only that section; zero admin-tier permissions
  is refused like bad credentials; a staff-session token never passes `EnsureBackOffice`
  even for a supervisor; `is_admin` is unaffected); sales/stock report location
  filtering (a location outside `locationIdsWhere()` is refused even though back-office
  login itself is "anywhere"); `requires_supervisor` enforcement both ways (a
  cashier-safe discount succeeds for a cashier, a normal one 403s
  `discount_needs_supervisor`); per-location threshold overrides (variance approval and
  low-stock) falling back to config when null.

One more, learned the hard way: **`StaffLogin` must set the team context itself.** Login
runs *before* a staff session exists, so `EnsureStaffSession` hasn't run — and reading the
user's permissions for the login response then returns an empty list. Not an error;
silently empty. That is the failure mode this document warns about, and it happened in our
own code within an hour of writing the warning. The login response's permission list is
asserted in a test for exactly that reason.
