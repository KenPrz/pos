# RBAC v2 and Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Role-template CRUD, per-user direct permission grants, permission-gated back-office access, a Settings surface (business identity + per-location thresholds), and the audit's confirmed authorization fixes — per `docs/superpowers/specs/2026-07-22-rbac-v2-and-settings-design.md`.

**Architecture:** Permissions stay a code catalog (`Permissions.php`); roles become data (`role_templates` + pivot) materialized into spatie's per-location role rows by a rewritten `RoleProvisioner`. Direct grants write the already-existing `model_has_permissions` table via a new `PermissionAssignments` class mirroring `RoleAssignments`. A new `AdminAccess` domain service ("holds it anywhere" over direct table joins) powers `EnsureBackOffice` (replacing `EnsureAdmin`), the admin FormRequest sweep, and the session's `sections` list. Settings = a keyed jsonb table with config fallback + two nullable per-location threshold columns.

**Tech Stack:** Laravel 13.20 / PHP 8.5, spatie/laravel-permission (teams, `location_id`), Pest vs real Postgres; Next.js 16 + React Query back office.

**Spec:** `docs/superpowers/specs/2026-07-22-rbac-v2-and-settings-design.md`

## Global Constraints

- Repo conventions (CLAUDE.md, `docs/04-backend-conventions.md`): one action = one route = one controller; actions take Input DTOs, never touch HTTP; `declare(strict_types=1)` everywhere; no `env()` outside `config/`; money integer cents; **no DELETE verb anywhere under `/admin`** (template delete is `POST /admin/roles/{id}/delete`); admin writes audited via `AuditLogger::record()`; financial records untouched.
- **Never read role/permission assignments through spatie relations** (`roles()`, `permissions()`, `getAllPermissions()`) — they silently scope to the current team. All new assignment/access code uses direct `DB::table()` joins like `RoleAssignments` does.
- `Gate::before` admin bypass (`AppServiceProvider::grantAdminsEverything()`) stays untouched.
- Postgres CHECK constraints evaluate per-statement — keep `UpdateUser`'s roles → pin → columns ordering; slot `PermissionAssignments::sync` right after roles.
- Backend tests: `POS_DEV_DB_PORT=5434 docker compose -f compose.dev.yml up -d db` (host 5432 is squatted on this machine), run `cd backend && DB_PORT=5434 ./vendor/bin/pest -d memory_limit=512M` (baseline at branch start: run once and record; expect ~497). Containerized gate: `make test-backend`.
- Back-office tests: `cd frontend/back-office && npm test && npm run typecheck` (baseline 133; if native node_modules is broken on this host, run in the dev container with `--user node`).
- Commit style: repo voice (`Rbac: ...`, `Settings: ...`, `Back office: ...`, `e2e: ...`, `Docs: ...`), imperative, **no attribution trailer**.
- Error envelope: domain exceptions extend `App\Exceptions\Domain\DomainException` (`errorCode()`, `httpStatus()`, `details()`), auto-rendered by `ApiErrorEnvelope`.
- The three new permissions (exact strings): `report.stock.view`, `settings.manage`, `role.manage`. Admin-tier section list (exact, ordered): `catalog.manage`, `user.manage`, `location.manage`, `register.enroll`, `audit.view`, `report.sales.view`, `report.stock.view`, `settings.manage`, `role.manage`.
- Wire shapes follow existing conventions: lists as `{data:{items:[...]}}`, single entities as `{data:{role:{...}}}` etc., matching `catalogEntity` in the back office.

---

### Task 1: de9bc07 consistency sweep (unbreaks `make e2e`)

**Files:**
- Modify: `Makefile` (line ~16 `POS_SEED_CATALOGS ?= grocery`; line ~143 `POS_ADMIN_PASSWORD=admin-dev-password`)
- Modify: `CLAUDE.md` (two `default: grocery` mentions), `docs/06-roadmap.md` (one), `.env.example`, `backend/.env.example`
- Modify: `docs/user-manual/capture_screenshots.mjs` (ADMIN password constant)

- [ ] **Step 1: Makefile**

Change `POS_SEED_CATALOGS ?= grocery` → `POS_SEED_CATALOGS ?= restaurant` (its comment "Mirrors config/pos.php's default" becomes true again — config default is `restaurant` since de9bc07). In the `e2e` target's admin-day line, change `POS_ADMIN_PASSWORD=admin-dev-password` → `POS_ADMIN_PASSWORD=password`.

- [ ] **Step 2: Docs and examples**

Grep-driven: `grep -rn "admin-dev-password\|default \`grocery\`\|POS_SEED_CATALOGS=grocery" CLAUDE.md docs/06-roadmap.md .env.example backend/.env.example docs/user-manual/capture_screenshots.mjs` — update every live-instruction hit to `password` / `restaurant` (historical `docs/superpowers/` records stay untouched). In `capture_screenshots.mjs` change `const ADMIN = { email: 'admin@pos.test', password: 'admin-dev-password' }` to `password: 'password'`.

- [ ] **Step 3: Verify**

```bash
make seed          # restaurant-only default now; printed table shows RST rows and admin password 'password'
curl -sf -X POST http://127.0.0.1:8000/api/v1/admin/login -H 'Content-Type: application/json' -d '{"email":"admin@pos.test","password":"password"}' | head -c 120
```
Expected: seed prints the back-office login as `password`; curl returns a token envelope. (Full `make e2e` runs in the final task.)

- [ ] **Step 4: Commit**

```bash
git add Makefile CLAUDE.md docs/06-roadmap.md .env.example backend/.env.example docs/user-manual/capture_screenshots.mjs
git commit -m "Config: finish the seed-default and admin-password rename sweep"
```

---

### Task 2: Three new permissions + stock-report re-gate

**Files:**
- Modify: `backend/app/Domain/Rbac/Permissions.php`
- Modify: `backend/app/Http/Requests/Admin/Reports/StockReportRequest.php`
- Test: `backend/tests/Feature/Rbac/PermissionCatalogTest.php` (create)

**Interfaces:**
- Produces: `Permissions::REPORT_STOCK_VIEW = 'report.stock.view'`, `Permissions::SETTINGS_MANAGE = 'settings.manage'`, `Permissions::ROLE_MANAGE = 'role.manage'`; all three in `Permissions::all()`; `REPORT_STOCK_VIEW` appended to `supervisor()`; new static `Permissions::grouped(): array` (label ⇒ list) for the catalog endpoint (Task 4).

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

use App\Domain\Rbac\Permissions;

it('carries the rbac-v2 permissions in the catalog', function (): void {
    expect(Permissions::all())
        ->toContain('report.stock.view')
        ->toContain('settings.manage')
        ->toContain('role.manage');
    expect(Permissions::supervisor())->toContain('report.stock.view');
    // grouped() covers the whole catalog, no strays
    expect(collect(Permissions::grouped())->flatten()->sort()->values()->all())
        ->toBe(collect(Permissions::all())->sort()->values()->all());
});
```

Run: `cd backend && DB_PORT=5434 ./vendor/bin/pest tests/Feature/Rbac/PermissionCatalogTest.php -d memory_limit=512M` → FAIL (undefined constant).

- [ ] **Step 2: Implement**

In `Permissions.php`: add consts in their groups — `REPORT_STOCK_VIEW = 'report.stock.view'` beside the reports group; `SETTINGS_MANAGE = 'settings.manage'` and `ROLE_MANAGE = 'role.manage'` beside the catalog/admin group. Append all three to `all()`; append `self::REPORT_STOCK_VIEW` to `supervisor()`. Add:

```php
    /** Catalog grouped for the roles UI. Labels are display copy; keys stay code. */
    public static function grouped(): array
    {
        return [
            'Orders' => [self::ORDER_OPEN, self::ORDER_LINE_ADD, self::ORDER_LINE_UPDATE, self::ORDER_LINE_VOID, self::ORDER_DISCOUNT_APPLY, self::ORDER_VOID, self::ORDER_REOPEN, self::ORDER_TRANSFER],
            'Payments & refunds' => [self::PAYMENT_TAKE, self::PAYMENT_VOID, self::REFUND_CREATE],
            'Shifts & drawer' => [self::SHIFT_OPEN, self::SHIFT_CLOSE, self::SHIFT_CASH_MOVEMENT, self::SHIFT_APPROVE_VARIANCE, self::DRAWER_NO_SALE],
            'Administration' => [self::CATALOG_VIEW, self::CATALOG_MANAGE, self::USER_MANAGE, self::LOCATION_MANAGE, self::REGISTER_ENROLL, self::SETTINGS_MANAGE, self::ROLE_MANAGE],
            'Reports' => [self::REPORT_Z_VIEW, self::REPORT_SALES_VIEW, self::REPORT_STOCK_VIEW, self::AUDIT_VIEW],
            'Stock' => [self::STOCK_ADJUST, self::STOCK_RECEIVE, self::STOCK_COUNT, self::STOCK_MOVEMENTS_VIEW],
        ];
    }
```

In `StockReportRequest.php` `authorize()`: `Permissions::REPORT_SALES_VIEW` → `Permissions::REPORT_STOCK_VIEW`.

- [ ] **Step 3: Green + no fallout**

Run the new test (PASS), then `DB_PORT=5434 ./vendor/bin/pest tests/Feature/Admin tests/Feature/Rbac -d memory_limit=512M` — the stock-report tests still pass because admins bypass via `Gate::before`; if any test asserted the old gate specifically, update it to the new permission (record it).

- [ ] **Step 4: Commit**

```bash
git add backend/app/Domain/Rbac/Permissions.php backend/app/Http/Requests/Admin/Reports/StockReportRequest.php backend/tests/Feature/Rbac/PermissionCatalogTest.php
git commit -m "Rbac: report.stock.view, settings.manage, role.manage permissions; stock report gated correctly"
```

---

### Task 3: `role_templates` schema, model, provisioner rewrite, CreateLocation fix

**Files:**
- Create: `backend/database/migrations/2026_07_23_000100_create_role_templates_tables.php`
- Create: `backend/app/Models/RoleTemplate.php`
- Modify: `backend/app/Domain/Rbac/RoleProvisioner.php` (rewrite around templates)
- Modify: `backend/app/Actions/Admin/Locations/CreateLocation.php` (provision on create)
- Test: `backend/tests/Feature/Rbac/RoleTemplatesTest.php` (create)

**Interfaces:**
- Consumes: `Permissions::cashier()/supervisor()` (seed source only), spatie `Role`/`Permission` models, `Roles::CASHIER/SUPERVISOR`.
- Produces: `RoleTemplate` model (uuid, `name`, `is_system`, `permissions()` belongsToMany via `role_template_permissions`); `RoleProvisioner::provisionGlobal()` (permissions + system templates), `provisionForLocation(Location)` (materialize every template), `syncTemplate(RoleTemplate)` (re-materialize everywhere), `renameMaterialized(string $old, string $new)`. Tasks 4/5/6 rely on these exact names.

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

use App\Domain\Rbac\RoleProvisioner;
use App\Models\Location;
use App\Models\RoleTemplate;
use Illuminate\Support\Facades\DB;

it('seeds cashier and supervisor as system templates', function (): void {
    app(RoleProvisioner::class)->provisionGlobal();

    $cashier = RoleTemplate::query()->where('name', 'cashier')->firstOrFail();
    expect($cashier->is_system)->toBeTrue();
    expect($cashier->permissions()->pluck('name')->all())->toContain('order.open', 'payment.take');
});

it('materializes every template at a new location, including via the admin API', function (): void {
    $location = provisionedLocation(['code' => 'AA']);
    $admin = \App\Models\User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    $created = $this->postJson('/api/v1/admin/locations', [
        'name' => 'New Store', 'code' => 'NS', 'timezone' => 'Asia/Manila', 'prices_include_tax' => true,
    ], $headers)->assertStatus(201)->json('data.location');

    // The audit's confirmed bug: assigning a role at an API-created location must now work.
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Newhire', 'pin' => '7777',
        'roles' => [['location_id' => $created['id'], 'role' => 'cashier']],
    ], $headers)->assertStatus(201);

    expect(DB::table('roles')->where('location_id', $created['id'])->pluck('name')->sort()->values()->all())
        ->toBe(['cashier', 'supervisor']);
});

it('syncTemplate pushes an edited permission set to every location', function (): void {
    $a = provisionedLocation(['code' => 'TA']);
    $b = provisionedLocation(['code' => 'TB']);
    $template = RoleTemplate::query()->where('name', 'cashier')->firstOrFail();

    $void = \Spatie\Permission\Models\Permission::query()->where('name', 'order.line.void')->firstOrFail();
    $template->permissions()->syncWithoutDetaching([$void->id]);
    app(RoleProvisioner::class)->syncTemplate($template->fresh());

    foreach ([$a, $b] as $location) {
        $roleId = DB::table('roles')->where('name', 'cashier')->where('location_id', $location->id)->value('id');
        $held = DB::table('role_has_permissions')->where('role_id', $roleId)
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->pluck('permissions.name');
        expect($held)->toContain('order.line.void');
    }
});
```

Run → FAIL (missing table/model).

- [ ] **Step 2: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role templates: the runtime definition of a role. Spatie's per-location role rows
 * are materialized copies kept in sync by RoleProvisioner — a template is global,
 * assignment stays per-location. Client-visible, so uuid pk (spatie's own roles
 * table keeps its bigint id; it is reference data, never exposed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
            $table->unique('name');
        });

        Schema::create('role_template_permissions', function (Blueprint $table): void {
            $table->uuid('role_template_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['role_template_id', 'permission_id']);
            $table->foreign('role_template_id')->references('id')->on('role_templates')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_template_permissions');
        Schema::dropIfExists('role_templates');
    }
};
```

- [ ] **Step 3: Model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Permission;

class RoleTemplate extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_template_permissions');
    }
}
```

(Match the repo's cast style — check a sibling model; if they use `protected $casts = [...]` arrays, use that form instead. Keep whichever the codebase uses.)

- [ ] **Step 4: Provisioner rewrite**

Replace `RoleProvisioner`'s body (keep `GUARD`, `flushCache()`, the class docblock updated to describe templates):

```php
    public function provisionGlobal(): void
    {
        foreach (Permissions::all() as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);
        }

        // System templates seed once from the code catalog; after that the TABLE is
        // the runtime truth — an admin edit must never be clobbered by a reseed.
        $seed = [
            Roles::CASHIER => Permissions::cashier(),
            Roles::SUPERVISOR => Permissions::supervisor(),
        ];
        foreach ($seed as $name => $permissions) {
            $template = RoleTemplate::query()->firstOrCreate(['name' => $name], ['is_system' => true]);
            if ($template->wasRecentlyCreated) {
                $ids = Permission::query()->whereIn('name', $permissions)->pluck('id')->all();
                $template->permissions()->sync($ids);
            }
        }

        $this->flushCache();
    }

    /** Materialize every template at this location. Call for every location, including new ones. */
    public function provisionForLocation(Location $location): void
    {
        foreach (RoleTemplate::query()->with('permissions')->get() as $template) {
            $this->materialize($template, $location);
        }
        $this->flushCache();
    }

    /** Push a template's current definition to every location (after create/edit). */
    public function syncTemplate(RoleTemplate $template): void
    {
        $template->loadMissing('permissions');
        foreach (Location::query()->get() as $location) {
            $this->materialize($template, $location);
        }
        $this->flushCache();
    }

    /** After a template rename: rename the materialized per-location rows in place. */
    public function renameMaterialized(string $old, string $new): void
    {
        Role::query()->where('name', $old)->where('guard_name', self::GUARD)
            ->whereNotNull('location_id')->update(['name' => $new]);
        $this->flushCache();
    }

    private function materialize(RoleTemplate $template, Location $location): void
    {
        $role = Role::query()->firstOrCreate([
            'name' => $template->name,
            'guard_name' => self::GUARD,
            'location_id' => $location->id,
        ]);
        $role->syncPermissions($template->permissions->pluck('name')->all());
    }
```

- [ ] **Step 5: CreateLocation provisions**

In `CreateLocation`: constructor gains `private readonly RoleProvisioner $provisioner`; inside the existing `DB::transaction`, after `Location::create(...)` and before the audit call: `$this->provisioner->provisionForLocation($location);`.

- [ ] **Step 6: Green + full regression**

New tests PASS; then `DB_PORT=5434 ./vendor/bin/pest tests/Feature -d memory_limit=512M` — the whole Feature suite must stay green (provisioning semantics unchanged for seeded paths; `UserManagementTest`'s "throws loudly when unprovisioned" test still passes because it fabricates the location without any provisioning).

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/2026_07_23_000100_create_role_templates_tables.php backend/app/Models/RoleTemplate.php backend/app/Domain/Rbac/RoleProvisioner.php backend/app/Actions/Admin/Locations/CreateLocation.php backend/tests/Feature/Rbac/RoleTemplatesTest.php
git commit -m "Rbac: role templates as data; locations provision roles on create"
```

---

### Task 4: Role CRUD API + permission catalog endpoint

**Files:**
- Create: `backend/app/Actions/Admin/Roles/{ListRoles,CreateRole,UpdateRole,DeleteRole}.php` + matching Input DTOs beside them (follow the folder's convention — check how `Actions/Admin/Catalog` stores Inputs)
- Create: `backend/app/Http/Requests/Admin/Roles/{ListRolesRequest,CreateRoleRequest,UpdateRoleRequest,DeleteRoleRequest}.php`
- Create: `backend/app/Http/Controllers/Admin/Roles/{ListRolesController,CreateRoleController,UpdateRoleController,DeleteRoleController,ListPermissionsController}.php`
- Create: `backend/app/Http/Resources/AdminRoleResource.php`
- Create: `backend/app/Exceptions/Domain/{RoleTemplateIsSystem,RoleTemplateInUse}.php`
- Modify: `backend/routes/api.php` (admin group), `backend/app/Http/Requests/Admin/Users/{CreateUserRequest,UpdateUserRequest}.php` (dynamic role validation)
- Test: `backend/tests/Feature/Admin/RoleCrudTest.php`

**Interfaces:**
- Consumes: `RoleTemplate`, `RoleProvisioner::{syncTemplate,renameMaterialized}`, `Permissions::{all,grouped}`, `AuditLogger`.
- Produces routes (all in the admin group, gated `Permissions::ROLE_MANAGE` in the FormRequests — plain `$this->user()->can(...)` for now; Task 6's sweep converts):
  - `GET /admin/roles` → `{data:{items:[{id,name,is_system,permissions:[...],assigned_users}]}}`
  - `POST /admin/roles` `{name, permissions:[...]}` → 201 `{data:{role:{...}}}`
  - `PATCH /admin/roles/{roleTemplate}` `{name?, permissions?}` → `{data:{role:{...}}}`
  - `POST /admin/roles/{roleTemplate}/delete` → 200 `{data:{deleted:true}}`
  - `GET /admin/permissions` → `{data:{groups:[{label,permissions:[...]}]}}` (authorize: `role.manage` **or** `user.manage`)
- Audit actions: `admin.role.create`, `admin.role.update`, `admin.role.delete`.

- [ ] **Step 1: Failing tests** — write `RoleCrudTest.php` covering, in the file's existing admin-test idiom (admin token headers, envelope assertions):

```php
<?php

declare(strict_types=1);

use App\Models\RoleTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'RC']);
    $this->admin = User::factory()->admin()->create();
    $this->headers = ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken];
});

it('lists templates with permissions and assignment counts', function (): void {
    $res = $this->getJson('/api/v1/admin/roles', $this->headers)->assertOk()->json('data.items');
    expect(collect($res)->pluck('name'))->toContain('cashier', 'supervisor');
    expect(collect($res)->firstWhere('name', 'cashier')['permissions'])->toContain('order.open');
});

it('creates a custom template and materializes it at every location', function (): void {
    $res = $this->postJson('/api/v1/admin/roles', [
        'name' => 'shift-lead', 'permissions' => ['order.open', 'order.line.add', 'shift.approve_variance'],
    ], $this->headers)->assertStatus(201)->json('data.role');

    expect($res['is_system'])->toBeFalse();
    expect(DB::table('roles')->where('name', 'shift-lead')->where('location_id', $this->location->id)->exists())->toBeTrue();
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.role.create', 'entity_id' => $res['id']]);

    // assignable immediately
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Lead', 'pin' => '8888',
        'roles' => [['location_id' => $this->location->id, 'role' => 'shift-lead']],
    ], $this->headers)->assertStatus(201);
});

it('edits a permission set and syncs the materialized rows', function (): void {
    $cashier = RoleTemplate::query()->where('name', 'cashier')->firstOrFail();
    $this->patchJson("/api/v1/admin/roles/{$cashier->id}", [
        'permissions' => ['order.open', 'order.line.add'],
    ], $this->headers)->assertOk();

    $roleId = DB::table('roles')->where('name', 'cashier')->where('location_id', $this->location->id)->value('id');
    $held = DB::table('role_has_permissions')->where('role_id', $roleId)
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->pluck('permissions.name');
    expect($held->sort()->values()->all())->toBe(['order.line.add', 'order.open']);
});

it('refuses to rename or delete a system template', function (): void {
    $cashier = RoleTemplate::query()->where('name', 'cashier')->firstOrFail();
    $this->patchJson("/api/v1/admin/roles/{$cashier->id}", ['name' => 'checkout'], $this->headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'role_template_is_system');
    $this->postJson("/api/v1/admin/roles/{$cashier->id}/delete", [], $this->headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'role_template_is_system');
});

it('refuses to delete a template that is assigned somewhere', function (): void {
    $this->postJson('/api/v1/admin/roles', ['name' => 'temp', 'permissions' => ['order.open']], $this->headers);
    $template = RoleTemplate::query()->where('name', 'temp')->firstOrFail();
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Holder', 'pin' => '9191',
        'roles' => [['location_id' => $this->location->id, 'role' => 'temp']],
    ], $this->headers)->assertStatus(201);

    $this->postJson("/api/v1/admin/roles/{$template->id}/delete", [], $this->headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'role_template_in_use');
});

it('deletes an unassigned custom template everywhere', function (): void {
    $this->postJson('/api/v1/admin/roles', ['name' => 'ghost', 'permissions' => ['order.open']], $this->headers);
    $template = RoleTemplate::query()->where('name', 'ghost')->firstOrFail();
    $this->postJson("/api/v1/admin/roles/{$template->id}/delete", [], $this->headers)->assertOk();
    expect(RoleTemplate::query()->where('name', 'ghost')->exists())->toBeFalse();
    expect(DB::table('roles')->where('name', 'ghost')->exists())->toBeFalse();
});

it('serves the grouped permission catalog', function (): void {
    $groups = $this->getJson('/api/v1/admin/permissions', $this->headers)->assertOk()->json('data.groups');
    expect(collect($groups)->pluck('label'))->toContain('Orders', 'Reports');
});

it('rejects unknown permission names', function (): void {
    $this->postJson('/api/v1/admin/roles', ['name' => 'bad', 'permissions' => ['not.a.permission']], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
```

Run → FAIL (404s).

- [ ] **Step 2: Exceptions**

Both in the `OrderHasPayments` shape: `RoleTemplateIsSystem` (`errorCode 'role_template_is_system'`, 422, message "System roles keep their name."), `RoleTemplateInUse` (`'role_template_in_use'`, 422, message "Unassign this role everywhere first.", details `['role_template_id' => ..., 'assigned_users' => n]`).

- [ ] **Step 3: Actions**

Follow the `CreateTaxRate` trio pattern exactly (Input DTO from request `toInput()`, `DB::transaction`, audit). Key logic:

- `CreateRole`: validate-normalized `name` (lowercase, trimmed — add `'name' => ['required','string','max:60','regex:/^[a-z0-9][a-z0-9 _-]*$/i', Rule::unique('role_templates','name')]` in the request), create template (`is_system => false`), `permissions()->sync` resolved ids (`Permission::whereIn('name', $in->permissions)->pluck('id')`), `$this->provisioner->syncTemplate($template)`, audit `admin.role.create` with `['name' => ..., 'permissions' => ...]`.
- `UpdateRole`: `lockForUpdate()->findOrFail`; if `$in->name !== null && $in->name !== $template->name`: throw `RoleTemplateIsSystem` when `$template->is_system`, else capture `$old = $template->name`, save new name, `$this->provisioner->renameMaterialized($old, $template->name)`. If `$in->permissions !== null`: sync pivot + `syncTemplate`. Audit `admin.role.update` with changed keys (mirror `UpdateLocation`'s `'changed' => array_keys(...)` style plus before/after name).
- `DeleteRole`: throw `RoleTemplateIsSystem` if system. Count assignments: `DB::table('model_has_roles')->join('roles','roles.id','=','model_has_roles.role_id')->where('roles.name',$template->name)->whereNotNull('roles.location_id')->count()` → throw `RoleTemplateInUse` if > 0. Delete the materialized spatie rows (`Role::where('name',$template->name)->whereNotNull('location_id')->delete()` — cascades `role_has_permissions`), delete the template, flush the permission cache, audit `admin.role.delete`.
- `ListRoles`: templates with permission names + `assigned_users` count (one grouped query over `model_has_roles`→`roles` by role name — no N+1).

`AdminRoleResource`: `{id, name, is_system, permissions (sorted names), assigned_users}`.

`ListPermissionsController` (no action class needed — static data; keep it a plain controller returning `['data' => ['groups' => collect(Permissions::grouped())->map(fn ($perms, $label) => ['label' => $label, 'permissions' => $perms])->values()]]`, with `ListPermissionsRequest` whose `authorize()` is `can(ROLE_MANAGE) || can(USER_MANAGE)`).

Routes (inside the admin group, after users):

```php
Route::get('/roles', ListRolesController::class)->name('admin.roles.index');
Route::post('/roles', CreateRoleController::class)->name('admin.roles.create');
Route::patch('/roles/{roleTemplate}', UpdateRoleController::class)->name('admin.roles.update');
Route::post('/roles/{roleTemplate}/delete', DeleteRoleController::class)->name('admin.roles.delete');
Route::get('/permissions', ListPermissionsController::class)->name('admin.permissions.index');
```

- [ ] **Step 4: Dynamic role validation on users**

In `CreateUserRequest`/`UpdateUserRequest`: `'roles.*.role' => ['required','string','in:cashier,supervisor']` → `['required','string', Rule::exists('role_templates', 'name')]`.

- [ ] **Step 5: Green + regression**

`RoleCrudTest` PASS; then `DB_PORT=5434 ./vendor/bin/pest tests/Feature/Admin tests/Feature/Rbac tests/Arch -d memory_limit=512M` green (arch tests enforce final actions etc. — follow the existing action file conventions).

- [ ] **Step 6: Commit**

```bash
git add backend/app backend/routes/api.php backend/tests/Feature/Admin/RoleCrudTest.php
git commit -m "Rbac: role template CRUD and the grouped permission catalog endpoint"
```

---

### Task 5: `PermissionAssignments` + `permissions[]` on users

**Files:**
- Create: `backend/app/Domain/Rbac/PermissionAssignments.php`
- Modify: `backend/app/Http/Requests/Admin/Users/{CreateUserRequest,UpdateUserRequest}.php`, the user Input DTOs, `backend/app/Actions/Admin/Users/{CreateUser,UpdateUser}.php`, `backend/app/Http/Resources/AdminUserResource.php`, plus the list/detail action that attaches `role_assignments` (attach `permission_assignments` the same way)
- Test: `backend/tests/Feature/Admin/UserDirectPermissionsTest.php`

**Interfaces:**
- Consumes: `model_has_permissions` (exists, team-scoped, columns `permission_id, model_type, model_id, location_id`), `RoleAssignments` as the structural template.
- Produces: `PermissionAssignments::sync(User $user, array $grants): array` (grants = `[{location_id, permission}]`, full-set replace; returns `['added' => [...], 'removed' => [...]]` pairs for the audit), `current(User): array`, `describe(User): array` / `describeMany(Collection): array` (adds `location_name` for display). Wire: users gain `permissions: [{location_id, location_name, permission}]` read, `permissions[]` write.

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

use App\Domain\Rbac\Permissions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->a = provisionedLocation(['code' => 'PA']);
    $this->b = provisionedLocation(['code' => 'PB']);
    $this->admin = User::factory()->admin()->create();
    $this->headers = ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken];
});

it('grants, lists, and replaces direct permissions per location', function (): void {
    $user = $this->postJson('/api/v1/admin/users', [
        'name' => 'Grantee', 'pin' => '6161',
        'roles' => [['location_id' => $this->a->id, 'role' => 'cashier']],
        'permissions' => [['location_id' => $this->a->id, 'permission' => 'order.discount.apply']],
    ], $this->headers)->assertStatus(201)->json('data.user');

    expect($user['permissions'])->toHaveCount(1);
    expect($user['permissions'][0]['permission'])->toBe('order.discount.apply');
    $this->assertDatabaseHas('model_has_permissions', ['model_id' => $user['id'], 'location_id' => $this->a->id]);

    // full-set replace: empty array clears
    $this->patchJson("/api/v1/admin/users/{$user['id']}", ['permissions' => []], $this->headers)->assertOk();
    expect(DB::table('model_has_permissions')->where('model_id', $user['id'])->count())->toBe(0);
});

it('unions direct grants with role permissions at the granted location only', function (): void {
    $user = User::factory()->withPin('6262')->create(['name' => 'Union']);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->a->id);
    $user->assignRole('cashier');
    $registrar->setPermissionsTeamId($this->b->id);
    $user->assignRole('cashier');

    app(\App\Domain\Rbac\PermissionAssignments::class)
        ->sync($user, [['location_id' => $this->a->id, 'permission' => Permissions::ORDER_DISCOUNT_APPLY]]);
    $registrar->forgetCachedPermissions();

    $registrar->setPermissionsTeamId($this->a->id);
    expect($user->fresh()->can(Permissions::ORDER_DISCOUNT_APPLY))->toBeTrue();
    $registrar->setPermissionsTeamId($this->b->id);
    expect($user->fresh()->can(Permissions::ORDER_DISCOUNT_APPLY))->toBeFalse();
});

it('rejects unknown permission names and unknown locations', function (): void {
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Bad', 'pin' => '6363',
        'roles' => [], 'permissions' => [['location_id' => $this->a->id, 'permission' => 'nope']],
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
```

(`$user->fresh()->can(...)` — spatie caches relations per model instance; fresh instances after team switches, and note the team-context discipline mirrors `PerLocationRolesTest`.) Run → FAIL.

- [ ] **Step 2: `PermissionAssignments`**

Mirror `RoleAssignments` structurally (read its file first; same docblock rationale about direct table access). Core:

```php
    /** @param array<array{location_id: string, permission: string}> $grants
     *  @return array{added: array, removed: array} */
    public function sync(User $user, array $grants): array
    {
        $before = $this->current($user);

        DB::table('model_has_permissions')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->delete();

        $rows = [];
        foreach ($grants as $grant) {
            $permissionId = DB::table('permissions')
                ->where('name', $grant['permission'])
                ->where('guard_name', RoleProvisioner::GUARD)
                ->value('id');
            if ($permissionId === null) {
                throw new RuntimeException("Permission '{$grant['permission']}' is not provisioned.");
            }
            $rows[] = [
                'permission_id' => $permissionId,
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->getKey(),
                'location_id' => $grant['location_id'],
            ];
        }
        if ($rows !== []) {
            DB::table('model_has_permissions')->insert($rows);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->diff($before, $grants);
    }
```

`current()`: join `model_has_permissions` → `permissions`, select `location_id, permissions.name as permission`. `describe()/describeMany()`: additionally join `locations` for `location_name`, grouped by user id (copy `RoleAssignments::rows()`'s batching so user lists stay N+1-free). `diff()`: set difference on `location_id|permission` composite keys → `['added' => [...], 'removed' => [...]]`.

- [ ] **Step 3: Requests, DTOs, actions, resource**

- Requests: `'permissions' => ['sometimes','array']`, `'permissions.*.location_id' => ['required','uuid','exists:locations,id']`, `'permissions.*.permission' => ['required','string', Rule::in(Permissions::all())]`; thread through `toInput()` (create: default `[]`; update: nullable "not provided" like roles).
- `CreateUser`: after `$this->roles->sync(...)`, add `$this->permissions->sync($user, $in->permissions);` (constructor DI). Include `'permissions' => $in->permissions` in the audit payload when non-empty.
- `UpdateUser`: same placement (after roles, before pin/fill — the CHECK-ordering comment applies); only when provided; add the returned diff to the audit payload when non-empty.
- `AdminUserResource`: `'permissions' => $this->permission_assignments ?? []` — attach in the same actions that attach `role_assignments` via `describe()/describeMany()`.

- [ ] **Step 4: Green + full Feature run, commit**

```bash
git add backend/app backend/tests/Feature/Admin/UserDirectPermissionsTest.php
git commit -m "Rbac: per-location direct permission grants on users"
```

---

### Task 6: `AdminAccess` + `EnsureBackOffice` + login + FormRequest sweep + session sections

**Files:**
- Create: `backend/app/Domain/Rbac/AdminAccess.php`
- Create: `backend/app/Http/Middleware/EnsureBackOffice.php`; delete `EnsureAdmin.php` (git rm) after repointing
- Create: `backend/app/Http/Requests/Concerns/AuthorizesBackOffice.php`
- Modify: `backend/bootstrap/app.php` (alias `'admin' => EnsureBackOffice::class`), `backend/app/Actions/Admin/AdminLogin.php`, `backend/app/Http/Resources/AdminSessionResource.php`, every FormRequest under `backend/app/Http/Requests/Admin/**` (sweep), `backend/app/Http/Requests/Admin/Reports/{SalesReportRequest,StockReportRequest}.php` (location scoping)
- Test: `backend/tests/Feature/Admin/BackOfficeAccessTest.php`

**Interfaces:**
- Produces: `AdminAccess::holdsAnywhere(User, string): bool`, `allHeld(User): array`, `sectionsFor(User): array` (ordered per Global Constraints; `is_admin` ⇒ full list), `locationIdsWhere(User, string): ?array` (null = all), `holdsAnyAdminSection(User): bool`; `AdminSessionResource` gains `'sections' => [...]`; `AuthorizesBackOffice::allowsBackOffice(string $permission): bool` used by every admin FormRequest.

- [ ] **Step 1: Failing tests** — the access matrix:

```php
<?php

declare(strict_types=1);

use App\Domain\Rbac\Permissions;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->a = provisionedLocation(['code' => 'BA']);
    $this->b = provisionedLocation(['code' => 'BB']);
});

function boUser(array $grants): array
{
    $user = User::factory()->create(['email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('secret-pass')]);
    app(\App\Domain\Rbac\PermissionAssignments::class)->sync($user, $grants);
    $token = $user->createToken('t')->plainTextToken;

    return [$user, ['Authorization' => 'Bearer '.$token]];
}

it('logs a non-admin with an admin-tier permission into the back office with scoped sections', function (): void {
    [$user] = boUser([['location_id' => $this->a->id, 'permission' => Permissions::REPORT_SALES_VIEW]]);

    $session = $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => 'secret-pass'])
        ->assertOk()->json('data');
    expect($session['sections'])->toBe(['report.sales.view']);
});

it('rejects login for users with no admin-tier permission, same envelope as bad credentials', function (): void {
    $user = User::factory()->create(['email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('secret-pass')]);
    $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => 'secret-pass'])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
});

it('lets a report-only user read reports at the granted location and nothing else', function (): void {
    [, $headers] = boUser([['location_id' => $this->a->id, 'permission' => Permissions::REPORT_SALES_VIEW]]);

    $this->getJson("/api/v1/admin/reports/sales?location_id={$this->a->id}&from=2026-07-01&to=2026-07-02&group_by=day", $headers)->assertOk();
    $this->getJson("/api/v1/admin/reports/sales?location_id={$this->b->id}&from=2026-07-01&to=2026-07-02&group_by=day", $headers)->assertStatus(403);
    $this->getJson('/api/v1/admin/users', $headers)->assertStatus(403);
    $this->postJson('/api/v1/admin/categories', ['name' => 'X'], $headers)->assertStatus(403);
});

it('keeps full access for is_admin', function (): void {
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    $this->getJson('/api/v1/admin/users', $headers)->assertOk();
    $session = $this->postJson('/api/v1/admin/login', ['email' => $admin->email, 'password' => 'admin-dev-password'])->json('data');
    // seeded factory password differs — assert sections via a fresh login only if factory sets a known password;
    // otherwise assert sectionsFor directly:
    expect(app(\App\Domain\Rbac\AdminAccess::class)->sectionsFor($admin))->toContain('catalog.manage', 'role.manage');
});
```

(Adapt the factory-password detail to `UserFactory` reality — read it; if `admin()` sets no password, drop the login line and keep the `sectionsFor` assertion.) Run → FAIL.

- [ ] **Step 2: `AdminAccess`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Holds it anywhere" resolution for the back office. Admin-tier surfaces are
 * global, so access is granted when the user holds the permission at ANY location,
 * via a role or a direct grant. Direct table joins on purpose — spatie's relations
 * scope to the current team and answer the wrong question silently (CLAUDE.md).
 */
final class AdminAccess
{
    public const array SECTIONS = [
        Permissions::CATALOG_MANAGE, Permissions::USER_MANAGE, Permissions::LOCATION_MANAGE,
        Permissions::REGISTER_ENROLL, Permissions::AUDIT_VIEW, Permissions::REPORT_SALES_VIEW,
        Permissions::REPORT_STOCK_VIEW, Permissions::SETTINGS_MANAGE, Permissions::ROLE_MANAGE,
    ];

    public function holdsAnywhere(User $user, string $permission): bool
    {
        return $user->is_admin || in_array($permission, $this->allHeld($user), true);
    }

    /** @return array<string> every permission held at any location, role-derived or direct */
    public function allHeld(User $user): array
    {
        $viaRoles = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->pluck('permissions.name');

        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', $user->getMorphClass())
            ->where('model_has_permissions.model_id', $user->getKey())
            ->pluck('permissions.name');

        return $viaRoles->merge($direct)->unique()->values()->all();
    }

    /** @return array<string> the admin-tier sections this user may see, in canonical order */
    public function sectionsFor(User $user): array
    {
        if ($user->is_admin) {
            return self::SECTIONS;
        }

        return array_values(array_intersect(self::SECTIONS, $this->allHeld($user)));
    }

    public function holdsAnyAdminSection(User $user): bool
    {
        return $user->is_admin || $this->sectionsFor($user) !== [];
    }

    /** @return array<string>|null location ids where the permission is held; null = all (admin) */
    public function locationIdsWhere(User $user, string $permission): ?array
    {
        if ($user->is_admin) {
            return null;
        }

        $viaRoles = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('permissions.name', $permission)
            ->pluck('model_has_roles.location_id');

        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', $user->getMorphClass())
            ->where('model_has_permissions.model_id', $user->getKey())
            ->where('permissions.name', $permission)
            ->pluck('model_has_permissions.location_id');

        return $viaRoles->merge($direct)->unique()->values()->all();
    }
}
```

- [ ] **Step 3: Middleware, login, session, trait**

- `EnsureBackOffice`: copy `EnsureAdmin`'s shape; condition becomes `! $user instanceof User || ! $user->is_active || ! $this->access->holdsAnyAdminSection($user)` → throw the existing `AdminAccessRequired` (constructor-inject `AdminAccess`). Repoint the alias in `bootstrap/app.php`; `git rm` `EnsureAdmin.php`. Keep the attributes-not-abilities docblock, updated.
- `AdminLogin`: replace the `is_admin` condition with `$this->access->holdsAnyAdminSection($user)` (keeping `is_active`, password checks, and the single `InvalidCredentials` failure for every case).
- `AdminSessionResource`: add `'sections' => app(AdminAccess::class)->sectionsFor($this->user)` and `'report_location_ids' => $reportLocations` where `$reportLocations` is `null` for admins, else the unique union of `locationIdsWhere($user, REPORT_SALES_VIEW)` and `locationIdsWhere($user, REPORT_STOCK_VIEW)` — the client uses it to filter the location switcher (match how the resource reaches its user).
- `AuthorizesBackOffice` trait (`app/Http/Requests/Concerns/`):

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Domain\Rbac\AdminAccess;
use App\Models\User;

trait AuthorizesBackOffice
{
    protected function allowsBackOffice(string $permission): bool
    {
        $user = $this->user();

        return $user instanceof User
            && ($user->is_admin || app(AdminAccess::class)->holdsAnywhere($user, $permission));
    }
}
```

- [ ] **Step 4: The sweep**

`grep -rln '\->can(Permissions::' backend/app/Http/Requests/Admin` — for every hit: add the trait, and replace `return $this->user()->can(Permissions::X);` with `return $this->allowsBackOffice(Permissions::X);`. Do not touch requests outside `Requests/Admin` (register tier keeps `can()` under team context).

- [ ] **Step 5: Reports location scoping**

In `SalesReportRequest` and `StockReportRequest`, add a `withValidator()` (or `after()` per the file's existing style — mirror `CreateUserRequest`'s `withValidator` idiom) that, when the user is not admin, fails validation with a 403-style `AuthorizationException` if `$this->input('location_id')` is not in `AdminAccess::locationIdsWhere($user, <the request's permission>)`. Throwing `Illuminate\Auth\Access\AuthorizationException` maps to the 403 `forbidden` envelope.

- [ ] **Step 6: Green + full suite + commit**

`BackOfficeAccessTest` PASS; `DB_PORT=5434 ./vendor/bin/pest -d memory_limit=512M` fully green (the sweep must not change admin behavior anywhere — admins pass both paths).

```bash
git add backend
git commit -m "Rbac: permission-based back-office access via AdminAccess and EnsureBackOffice"
```

---

### Task 7: Settings backend (business identity)

**Files:**
- Create: `backend/database/migrations/2026_07_23_000200_create_settings_table.php`
- Create: `backend/app/Domain/Settings/Settings.php`
- Create: `backend/app/Actions/Admin/Settings/{GetSettings,UpdateSettings}.php` (+ Input), `backend/app/Http/Requests/Admin/Settings/{GetSettingsRequest,UpdateSettingsRequest}.php`, `backend/app/Http/Controllers/Admin/Settings/{GetSettingsController,UpdateSettingsController}.php`
- Modify: `backend/routes/api.php`, `backend/app/Http/Resources/ReceiptResource.php` (business block reads through `Settings`)
- Test: `backend/tests/Feature/Admin/SettingsTest.php`

**Interfaces:**
- Produces: `Settings::get(string $key): mixed` (DB value ?? config fallback), `Settings::all(): array` (registry keys → effective values + source `'db'|'config'`), `Settings::set(string $key, mixed $value): void`; registry `Settings::REGISTRY = ['business.name' => 'pos.business.name', 'business.address' => 'pos.business.address', 'business.tax_id' => 'pos.business.tax_id']`. Routes: `GET /admin/settings` → `{data:{settings:[{key,value,source}]}}`, `PATCH /admin/settings` `{settings:{'business.name': 'X', ...}}` → same shape; both `settings.manage` via the trait; audit `admin.settings.update` with changed keys.

- [ ] **Step 1: Failing tests**

```php
it('falls back to config until a value is set, then prefers the database', function (): void {
    config(['pos.business.name' => 'Env Trading Co']);
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    $before = $this->getJson('/api/v1/admin/settings', $headers)->assertOk()->json('data.settings');
    expect(collect($before)->firstWhere('key', 'business.name'))->toMatchArray(['value' => 'Env Trading Co', 'source' => 'config']);

    $this->patchJson('/api/v1/admin/settings', ['settings' => ['business.name' => 'Manila Trading']], $headers)->assertOk();
    $after = $this->getJson('/api/v1/admin/settings', $headers)->json('data.settings');
    expect(collect($after)->firstWhere('key', 'business.name'))->toMatchArray(['value' => 'Manila Trading', 'source' => 'db']);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.settings.update']);
});

it('rejects unregistered keys', function (): void {
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    $this->patchJson('/api/v1/admin/settings', ['settings' => ['pos.currency' => 'USD']], $headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
```

Plus a receipt test: create a paid order fixture the way existing receipt tests do (find `tests/Feature/Orders` receipt coverage and copy its setup), set `business.name` via `Settings::set`, fetch the receipt, assert the new name appears. Run → FAIL.

- [ ] **Step 2: Migration + domain**

Migration: `settings` — `$table->text('key')->primary(); $table->jsonb('value'); $table->timestampsTz();`. Domain class per the interface above (`DB::table('settings')` directly — deliberately not a model, same reasoning as `AuditLogger`; json_encode on write, json_decode on read). Validation of keys against `REGISTRY` lives in the request (`Rule::in(array_keys(Settings::REGISTRY))` applied to the keys of the `settings` object — use a `withValidator` that iterates `array_keys($this->input('settings', []))`), values `['nullable','string','max:500']`.

- [ ] **Step 3: Wire receipt**

`ReceiptResource`'s `config('pos.business.name')` etc. → `app(Settings::class)->get('business.name')` (all three keys). The `required` boot check on `pos.business.name` stays (env fallback must still exist).

- [ ] **Step 4: Green + commit**

```bash
git add backend
git commit -m "Settings: business identity in the database with config fallback"
```

---

### Task 8: Per-location thresholds

**Files:**
- Create: `backend/database/migrations/2026_07_23_000300_add_location_threshold_columns.php`
- Modify: `backend/app/Models/Location.php` (fillable + casts), `backend/app/Http/Requests/Admin/Locations/{CreateLocationRequest,UpdateLocationRequest}.php` (+ Input DTOs), `backend/app/Actions/Shifts/ApproveVariance.php`, `backend/app/Http/Resources/CloseShiftResource.php` (+ its data source), `backend/app/Actions/Admin/Reports/StockReport.php`
- Test: `backend/tests/Feature/Admin/LocationThresholdsTest.php`

**Interfaces:**
- Produces: nullable `locations.variance_approval_threshold_cents` (integer) and `locations.low_stock_threshold` (numeric(12,3), matching stock qty scale). Null ⇒ `config('pos.shifts.variance_approval_threshold_cents')` / `config('pos.stock.low_threshold')`. Wire: both fields on location create/update/read (`AdminLocationResource` — add them).

- [ ] **Step 1: Failing tests**

```php
it('uses a location variance threshold override for approval requirement', function (): void {
    // Build on the existing shift-close test fixtures: find the CloseShift feature test,
    // copy its minimal setup (location, register, staff, open shift), then:
    // - set the location's variance_approval_threshold_cents to 10_000
    // - close with a 700-cent variance
    // - assert requires_approval is FALSE (config default 500 would have required it)
});

it('flags low stock per the location override', function (): void {
    // Existing StockReport test fixtures + location low_stock_threshold = 50,
    // variant with qty 20 => low; sibling location without override, qty 20, threshold 5 => not low.
});

it('accepts, persists, and returns the two fields through the admin API', function (): void {
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    $loc = $this->postJson('/api/v1/admin/locations', [
        'name' => 'T', 'code' => 'TH', 'timezone' => 'Asia/Manila', 'prices_include_tax' => true,
        'variance_approval_threshold_cents' => 10000, 'low_stock_threshold' => '50',
    ], $headers)->assertStatus(201)->json('data.location');
    expect($loc['variance_approval_threshold_cents'])->toBe(10000);

    $this->patchJson("/api/v1/admin/locations/{$loc['id']}", ['variance_approval_threshold_cents' => null], $headers)->assertOk();
});
```

Flesh the first two out against the real fixtures (read the existing CloseShift/StockReport tests first — reuse their helpers verbatim; the comment lines above are instructions to you, the implementer, and must be replaced by working code). Run → FAIL.

- [ ] **Step 2: Migration** — the `add_register_activation_columns` raw-statement style:

```php
DB::statement('alter table locations add column variance_approval_threshold_cents integer');
DB::statement('alter table locations add column low_stock_threshold numeric(12,3)');
DB::statement("alter table locations add constraint locations_variance_threshold_positive check (variance_approval_threshold_cents is null or variance_approval_threshold_cents >= 0)");
DB::statement('alter table locations add constraint locations_low_stock_threshold_positive check (low_stock_threshold is null or low_stock_threshold >= 0)');
```

with a `down()` reversing in opposite order.

- [ ] **Step 3: Consumers**

- `Location`: add both to `$fillable`; casts `variance_approval_threshold_cents => 'integer'` (leave the numeric as string per the repo's quantity discipline — check how `stock_levels.qty` is handled and match).
- `ApproveVariance`: load the location (`Register::findOrFail(...)->location` — it currently only takes `location_id`; adjust minimally) and `$threshold = (int) ($location->variance_approval_threshold_cents ?? config('pos.shifts.variance_approval_threshold_cents'));`.
- `CloseShiftResource` `requires_approval`: the resource needs the location's threshold. Find who constructs it (the CloseShift controller/action) and eager-load `shift->register->location`; in the resource: `$threshold = $this->register->location->variance_approval_threshold_cents ?? config('pos.shifts.variance_approval_threshold_cents');`. Verify the relation exists on `Shift` (register) — it does per the schema; add `->loadMissing('register.location')` at the construction site rather than lazy-loading in the resource.
- `StockReport`: `$location = Location::query()->findOrFail($in->locationId);` then `Quantity::fromString((string) ($location->low_stock_threshold ?? config('pos.stock.low_threshold')))`.
- Requests: create — `'variance_approval_threshold_cents' => ['sometimes','nullable','integer','min:0']`, `'low_stock_threshold' => ['sometimes','nullable','numeric','min:0']`; update — same with `sometimes`, added to the `safe()->only([...])` list.

- [ ] **Step 4: Green (new + existing shift/stock suites) + commit**

```bash
git add backend
git commit -m "Settings: per-location variance and low-stock thresholds with config fallback"
```

---

### Task 9: Enforce `requires_supervisor` on discounts

**Files:**
- Modify: `backend/app/Actions/Orders/ApplyDiscount.php`, `backend/app/Http/Requests/Orders/ApplyDiscountRequest.php`
- Create: `backend/app/Exceptions/Domain/DiscountNeedsSupervisor.php`
- Test: `backend/tests/Feature/Orders/DiscountSupervisorFlagTest.php`

**Interfaces:**
- Behavior contract (spec): flag `true` → actor must hold `order.discount.apply` (as today); flag `false` → `order.line.add` suffices. `RemoveDiscount` unchanged.

- [ ] **Step 1: Failing tests**

Use the Pest helpers (`provisionedLocation`, `registerAt`, `staffWithRole`, `staffHeaders`) and an order fixture copied from the existing ApplyDiscount feature test (read it first):

```php
it('lets a cashier apply a discount marked cashier-safe', function (): void {
    // discount factory with requires_supervisor => false; cashier session; POST discounts => 200/201
});

it('blocks a cashier from a supervisor-only discount with a clean 403', function (): void {
    // requires_supervisor => true; cashier session; expect 403, error.code 'discount_needs_supervisor'
});

it('lets a supervisor apply either kind', function (): void { /* both flags, supervisor session, both succeed */ });
```

(Replace the comment lines with working code against the real fixtures.) Run → FAIL (cashier currently 403s via the request gate before reaching the flag logic — the first test is the red one).

- [ ] **Step 2: Implement**

- `ApplyDiscountRequest::authorize()`: `Permissions::ORDER_DISCOUNT_APPLY` → `Permissions::ORDER_LINE_ADD` (the floor; the flag-dependent escalation moves into the action, mirroring how `SetLinePrepState` does in-action escalation — read `SetLinePrepState.php:34-46` and copy its `$user->can(...)` idiom, team context already set by `EnsureStaffSession`).
- `ApplyDiscount::execute()`: after loading the discount (inside the existing transaction/lock), add:

```php
        if ($discount->requires_supervisor) {
            $actor = User::query()->findOrFail($in->actorId);
            if (! $actor->can(Permissions::ORDER_DISCOUNT_APPLY)) {
                throw new DiscountNeedsSupervisor($discount->id);
            }
        }
```

- `DiscountNeedsSupervisor`: `errorCode 'discount_needs_supervisor'`, `httpStatus 403`, message "This discount needs a supervisor.", details `['discount_id' => ...]`.
- Register UI: deliberately unchanged in v1 (spec allows) — the Discount panel stays supervisor-visible; note it in the task report and the docs task.

- [ ] **Step 3: Green + existing discount/order suites + commit**

```bash
git add backend
git commit -m "Orders: requires_supervisor on discounts is enforced, cashier-safe discounts exist"
```

---

### Task 10: Back office — Roles tab, permission grants in UserEditor, api client

**Files:**
- Modify: `frontend/back-office/src/lib/api.ts` (types + endpoints), `frontend/back-office/src/admin/users/UsersSection.tsx` (becomes tabbed: Users | Roles), `frontend/back-office/src/admin/users/UserEditor.tsx` (permissions block)
- Create: `frontend/back-office/src/admin/users/RolesPanel.tsx`, `frontend/back-office/src/admin/users/RoleEditor.tsx`
- Test: `frontend/back-office/src/admin/users/RolesPanel.test.tsx`, extend `UserEditor.test.tsx`

**Interfaces:**
- Consumes: Task 4/5 wire shapes. api.ts additions:

```ts
export type Role = { id: string; name: string; is_system: boolean; permissions: string[]; assigned_users: number }
export type PermissionGroup = { label: string; permissions: string[] }
export type PermissionGrant = { location_id: string; location_name?: string; permission: string }
// ManagedUser gains: permissions: PermissionGrant[]
// api.roles = catalogEntity<Role>('roles', 'role') plus:
//   deleteRole: (id: string) => post(`/admin/roles/${id}/delete`, {})
//   permissionGroups: () => request<{ groups: PermissionGroup[] }>('/admin/permissions').then(r => r.groups)
```

- [ ] **Step 1: Failing tests** — `RolesPanel.test.tsx` in the section-test idiom (`@vitest-environment jsdom`, api module mock via `importOriginal`, QueryClientProvider wrapper, `afterEach(cleanup)`): renders template rows with name + assigned count; opens the editor on Edit; system template shows a disabled name input and no Delete button; custom template's Delete goes through `ConfirmDialog` and calls `api.deleteRole`; permission checkboxes grouped under the group labels; Save calls `api.roles.update` with `{permissions: [...]}`. `UserEditor.test.tsx` additions: a permissions `DataTable` renders existing grants (`location_name` + permission), add-row adds locally, Save includes `permissions` only when changed (mirror the existing roles-diff test if one exists; otherwise assert the PATCH body). Run → FAIL.

- [ ] **Step 2: Implement**

- `UsersSection` becomes the `PlacesSection` two-tab shape: `Tab = 'users' | 'roles'`, `Tabs`/`TabsList aria-label="Users tabs"`/`TabsContent`; the existing body moves into the `users` panel unchanged; `roles` renders `RolesPanel`.
- `RolesPanel`: `EntityTable<Role>` (columns: Name, Permissions count, Assigned users, system pill via `StatusPill`), editor toggle state like `UsersSection`'s `editing`.
- `RoleEditor`: form with name `Input` (disabled + explanatory helper text when `is_system`), grouped permission checkboxes (fetch `api.permissionGroups()` with `useQuery`; render each group as a fieldset with the label eyebrow and one `Checkbox` row per permission, checked from local `Set<string>` state), Save mutation (create vs update on `role === null`), Delete button (custom templates only) behind `ConfirmDialog` with copy "Delete this role? It must be unassigned everywhere." — surfacing the server's `role_template_in_use` message verbatim on failure (the `ApiError.message` → error banner pattern from `UserEditor`).
- `UserEditor`: below the roles block, a "Direct permissions" `DataTable<PermissionGrant>` + add-row (location `Select` with the `__none__` sentinel + permission `Select` fed from `api.permissionGroups()` flattened + Add `Button`), local add/remove only, `samePermissions` diff helper mirroring `sameRoles`, PATCH body includes `permissions` only when changed.

- [ ] **Step 3: Green + typecheck + commit**

```bash
cd frontend/back-office && npm test && npm run typecheck
git add frontend/back-office
git commit -m "Back office: role CRUD tab and direct permission grants on users"
```

---

### Task 11: Back office — sections gating, Settings section, location thresholds

**Files:**
- Modify: `frontend/back-office/src/lib/api.ts` (`AdminSession.sections`, persisted; `api.settings` endpoints; `Location` threshold fields), `frontend/back-office/src/admin/AdminApp.tsx` (thread sections), `frontend/back-office/src/admin/Shell.tsx` (gated nav + Settings section), `frontend/back-office/src/admin/places/LocationEditor.tsx` (two nullable numeric fields)
- Create: `frontend/back-office/src/admin/settings/SettingsSection.tsx`
- Test: `frontend/back-office/src/admin/Shell.test.tsx` (gating cases), `SettingsSection.test.tsx`, `LocationEditor.test.tsx` additions

**Interfaces:**
- Consumes: `sections: string[]` from the login payload (Task 6); settings wire shape (Task 7); location fields (Task 8).
- Mapping section-permission → sidebar item (exact): `catalog.manage`→Catalog, `user.manage`→Users (Users tab), `role.manage`→Users (Roles tab), `location.manage`|`register.enroll`→Locations & Registers, `report.sales.view`|`report.stock.view`→Reports (and the matching report tab), `audit.view`→Audit, `settings.manage`→Settings. Today is always visible; its tiles render per held permission (sales tiles `report.sales.view`, low-stock tile `report.stock.view`, audit strip `audit.view`).

- [ ] **Step 1: Failing tests** — `Shell.test.tsx` additions (stub-mock `SettingsSection` like the other sections): a user whose `sections` is `['report.sales.view']` sees Today + Reports only; `is_admin` (sections = full list) sees all seven nav items incl. Settings; clicking a hidden section is impossible (item absent). `SettingsSection.test.tsx`: renders the three fields from `api.settings.get`, saves changed keys only, surfaces the config/db source as helper text. `LocationEditor` additions: threshold inputs accept empty (null on wire) and integers; non-numeric input blocks save (mirror the cost-field invalid pattern). Run → FAIL.

- [ ] **Step 2: Implement**

- api.ts: `AdminUser` unchanged; `AdminSession` gains `sections: string[]` and `report_location_ids: string[] | null`; persist alongside the user (extend `adminToken.setUser` storage to a `pos.admin_sections` key or fold into the stored user object — pick the stored-user-object route: store `{...user, sections}` and strip on read; keep both `logout` and `handleUnauthorized` clearing it, which they already do via `clearUser`). `api.settings = { get: () => request<{settings: Setting[]}>('/admin/settings').then(r => r.settings), update: (settings) => patch('/admin/settings', {settings}) }` with `type Setting = { key: string; value: string | null; source: 'db' | 'config' }`. `Location` type gains `variance_approval_threshold_cents: number | null; low_stock_threshold: string | null`.
- `AdminApp`: capture `sections` and `report_location_ids` at login; on restore, read from storage; pass `sections` to `Shell`; when `report_location_ids` is non-null, filter the `locations` array handed to `Shell`/`AppSidebar` down to those ids (the switcher feeds Today and Reports, and a report-scoped user should only ever see their permitted locations).
- `Shell`: `Section` union gains `'settings'`; `navSections` built by filtering against `sections` per the mapping table (a small `SECTION_RULES` const). Users section receives which tabs to show (`user.manage` / `role.manage`); Reports likewise for its Sales/Stock tabs.
- `SettingsSection`: bespoke small form (the `LocationEditor` manual-state pattern): three `FieldRow`+`Input`s seeded from the query, helper text "from config — saving stores an override" when `source === 'config'`, save mutation PATCHing only changed keys, 401 → `onUnauthorized`.
- `LocationEditor`: two new fields after receipt footer, the `VariantEditor` cost-field optional-numeric pattern: raw string state, `'' → null`, integer parse for the cents field / decimal-string passthrough for the qty threshold (validate with the same regex family `money.ts` uses for quantities), invalid non-empty blocks save; `put('variance_approval_threshold_cents', parsed, location?.variance_approval_threshold_cents)` etc.

- [ ] **Step 3: Green + typecheck + commit**

```bash
cd frontend/back-office && npm test && npm run typecheck
git add frontend/back-office
git commit -m "Back office: permission-gated sections, Settings, per-location thresholds"
```

---

### Task 12: Full gates, e2e, docs

**Files:**
- Modify: `docs/05-rbac.md` (rewrite for v2), `docs/03-api.md` (new endpoints + changed session/catalog shapes), `docs/02-data-model.md` (new tables/columns), `docs/06-roadmap.md` + `CLAUDE.md` (records), `docs/manual/03-manager-guide.md` (roles no longer a fixed pair; Settings exists), `docs/user-manual/user-manual.md` chapters 8/10/11 (sections are permission-gated; roles editable; Settings section; keep edits factual and minimal — no new screenshots)

- [ ] **Step 1: Full backend + frontend gates**

```bash
make test          # backend + web + back-office suites in containers — record the new counts
```

All green. If the containerized backend run trips on anything env-dependent, reconcile before proceeding (the `POS_CURRENCY` precedent: pin config in tests, never touch compose/Makefile).

- [ ] **Step 2: e2e**

```bash
make e2e
```

All three scripts green — this also proves Task 1's password fix end-to-end. The scripts exercise cashier/supervisor flows that must be behaviorally identical post-RBAC-v2 (templates materialize the same rows).

- [ ] **Step 3: Docs**

- `docs/05-rbac.md`: rewrite the roles section — permission catalog still code; roles are admin-editable templates materialized per location; direct per-location grants; back-office access = admin-tier permission anywhere; `is_admin` unchanged; the requires_supervisor discount rule; keep the historical "Correction" (admin-not-a-role) narrative.
- `docs/03-api.md`: `/admin/roles*`, `/admin/permissions`, `/admin/settings`, users `permissions[]`, session `sections`, location threshold fields, discount 403 code `discount_needs_supervisor`, stock report permission.
- `docs/02-data-model.md`: `role_templates`, `role_template_permissions`, `settings`, the two location columns (with the null-means-config-default rule).
- Roadmap + CLAUDE.md: a new record in the established voice (suite counts from Step 1); CLAUDE.md gotchas gain one line: "AdminAccess/`holdsAnywhere` is the back-office authorization primitive — admin FormRequests never call bare `can()` (no team context there)."
- Manual edits: ch. 10 "two roles" phrasing → roles are manageable (Users → Roles tab); ch. 8 sidebar note that sections depend on permissions; ch. 11 mention per-location thresholds in the location editor; manager guide same facts. The manual CI workflow will rebuild the PDF on merge.

- [ ] **Step 4: Commit + final sweep**

```bash
git add docs CLAUDE.md
git commit -m "Docs: record RBAC v2 and Settings"
git log --oneline main..HEAD   # spec -> plan -> tasks in order
git status                     # clean
```
