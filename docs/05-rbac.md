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
`register.enroll` and `audit.view` are granted by **no role**. That is correct — only
admins do those things, and admins bypass. The permission names still exist because the
endpoints still name what they require.

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
| `audit.view` | The audit log |

Every permission marked **money leaves** is supervisor-or-above. That set is not a
coincidence or a judgement call — it is the fraud surface from `01-architecture.md`,
enumerated. The label covers value leaving the *business*, not only cash leaving a
drawer — a stock adjustment moves sellable inventory out of the count the same way a void
moves cash out of the till, which is why `stock.adjust` carries the label too. When adding
a permission, the question that decides its role is "can this be used to take value out of
the business without a customer noticing?"

## Roles

Seeded, not user-editable in v1. Two roles, deliberately coarse, plus the `admin` flag
that sits outside the role system entirely (see the correction above).

**`cashier`** — the shift they can run alone:
`order.open`, `order.line.add`, `order.line.update`, `payment.take`, `shift.open`,
`shift.close`, `catalog.view`, `report.z.view`

**`supervisor`** — everything a cashier can do, plus the fraud surface:
`order.line.void`, `order.discount.apply`, `order.void`, `order.reopen`,
`order.transfer`, `payment.void`, `refund.create`, `shift.cash_movement`,
`shift.approve_variance`, `drawer.no_sale`, `report.sales.view`, `stock.adjust`,
`stock.receive`, `stock.count`, `stock.movements.view`

**`admin`** — not a role. `users.is_admin` + `Gate::before`, per the correction above.

A cashier can open and close their own drawer without a supervisor, because requiring one
for a routine open would mean either a manager tied to the terminal all morning or a
manager's PIN written on a sticky note — and the second is what actually happens.
Variance *approval* is where the supervisor belongs; that's the moment worth their time.

## Back-office access

`POST /api/v1/admin/login` (`03-api.md`) is **admin-only** in v1 — there is no
supervisor or bookkeeper tier that can reach `/admin/*` while stopping short of full
admin. That is a scope decision, not an oversight, and it follows directly from how
team context works everywhere else in this system: `EnsureStaffSession` reads the
location off the *register* the request came from, so a role check never has to ask
the client which location it means. The back office has no register. A supervisor- or
bookkeeper-scoped back-office login would need to ask the client which location's roles
to check, which is exactly the client-supplied-scope hole per-location teams exist to
close (see "Wiring", above). Solving that properly — not bolting a location parameter
onto `AdminLogin` — is real design work, so it waits.

The deferral has a name and a trigger, from the M6 design spec's deferred table:
**supervisor/bookkeeper back-office access, revived by the first accountant** who needs
sales and audit visibility without order-void or user-management power. Until then,
`admin` is the only way into `/admin/*`, and `AdminLogin` refuses wrong email, wrong
password, deactivated, and non-admin identically (`401 invalid_credentials`) — the same
enumeration-safe shape as `StaffLogin`'s PIN refusal, extended to the back office. It's
also why `user.manage` guards `self_lockout` (`03-api.md`): with exactly one admin tier
and no location-scoped fallback, an admin who could revoke their own access would have
no one else able to undo it.

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

Permissions and roles are **seeded from code, never created at runtime**. The seeder is
the source of truth and is safe to re-run:

`RoleProvisioner` does both halves and is safe to re-run:

- `provisionGlobal()` — every permission in the catalog above. Permissions are global;
  only roles are team-scoped. It creates no admin role (see the correction).
- `provisionForLocation($location)` — `cashier` and `supervisor`, **once per location**,
  because with teams enabled a role row is per-team.

That last point is the one that surprises people: `Role::create(['name' => 'cashier'])`
creates a cashier for the *current* team only. Opening a new store means provisioning its
roles, so `CreateLocation` must call `provisionForLocation` inside the same action — a
location without roles is a store nobody can be assigned to.

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
- Roles are provisioned for a location created at runtime.

One more, learned the hard way: **`StaffLogin` must set the team context itself.** Login
runs *before* a staff session exists, so `EnsureStaffSession` hasn't run — and reading the
user's permissions for the login response then returns an empty list. Not an error;
silently empty. That is the failure mode this document warns about, and it happened in our
own code within an hour of writing the warning. The login response's permission list is
asserted in a test for exactly that reason.
