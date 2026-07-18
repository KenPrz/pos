# M6 — Back Office Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An admin never needs `psql` — catalog, users, locations/registers, reports, and the audit trail all manageable from a new back-office web app.

**Architecture:** New `/api/v1/admin/*` surface on the same Laravel API (bearer-token admin auth, `EnsureAdmin` middleware), following every standing convention: one route = one single-action controller = one final Action; FormRequest validates/authorizes/maps; every mutation audited; archive-never-delete. A second Next.js app at `frontend/back-office` (port 5175) with its own API client; `money.ts`, `tokens.css`, and the envelope unwrapper are **copied deliberately** (no monorepo tooling for three files). Spec: `docs/superpowers/specs/2026-07-18-m6-back-office-design.md`.

**Tech Stack:** Laravel 13 / PHP 8.5, PostgreSQL 18, Pest; Next.js 16 (app router) + React 19 + TanStack React Query v5 + TypeScript 7 + vitest/@testing-library.

## Global Constraints

- **Machine-local:** Postgres on host port **5433**; backend tests run as `cd backend && DB_PORT=5433 ./vendor/bin/pest` (baseline **397 green**). `php artisan` uses `backend/.env` (already 5433). NEVER edit `phpunit.xml` or committed config for ports.
- **Money integer cents; quantities milli-strings.** Validation failures **400 `validation_failed`**; domain refusals 409/422, one `DomainException` subclass per code (mirror `backend/app/Exceptions/Domain/OrderNotZero.php`). Actions `final`, one readonly Input DTO, own `DB::transaction`, no HTTP (`tests/Arch` enforces).
- **Archive, never delete** — no DELETE routes anywhere in this milestone. **Deactivate, never delete** users.
- **Eloquent `create()` never hydrates DB column defaults**; `jsonb` reorders keys (`toEqual`, never `toBe`); a constraint violation aborts the whole Postgres test transaction.
- Role reads/writes go to **`model_has_roles` directly** — never spatie's `roles()` relation (docs/05-rbac.md, bitten twice).
- Frontend gates per app: `npm test && npm run typecheck && npm run build` (register app baseline **79 green**; back-office starts fresh). Next's own type-check stays disabled (TS7); `tsc --noEmit` is the gate. TS is `erasableSyntaxOnly`.
- Register app dev port 5174; **back-office dev port 5175**; API 127.0.0.1:8000.
- Commits: imperative `M6: <what>`, NO co-author trailer. Branch: `m6-back-office` (already checked out).
- E2e scripts: credentials via env vars with `:?` guards; NEVER commit a live token. Seeder dev passwords/PINs printed at seed time are fine (dev fixtures, same class as the printed PINs).

## Existing surfaces you will reuse (verbatim)

- `AuditLogger::record(string $action, Model|string $entity, ?string $actorId = null, array $payload = [], ?string $registerId = null, ?string $ip = null): void`
- `SetStaffPin` action: `execute(SetStaffPinInput{userId, pin, actorId}): User` — does the HMAC lookup + cross-location collision check (`PinAlreadyInUse` 422).
- `EnrollRegister` action: creates a Register row + long-lived device token, returns `EnrolledRegister{register, plainTextToken}`; route `POST /api/v1/registers/enroll` already behind `auth:sanctum` — admin bearer tokens (Task 1) finally make it reachable.
- `User` model: fillable `name,email,password_hash,pin_hash,is_admin,is_active`; `password_hash` cast `hashed` (assign the PLAIN password to `password_hash` and the cast bcrypts it); `getAuthPassword()` maps. Schema CHECK: `email is not null OR pin_hash is not null`; unique on `lower(email)`.
- Model fillables: Product `name,description,category_id,kind,is_active`; ProductVariant `product_id,name,sku,barcode,price_cents,cost_cents,tax_rate_id,track_inventory` (+`is_active` — verify, add if missing); Category `name,parent_id,sort_order`; TaxRate `name,rate_micros,is_active`; ModifierGroup `name,min_select,max_select`; Modifier `group_id,name,price_delta_cents,position,is_active`; Discount `name,kind,percent_micros,amount_cents,scope,requires_supervisor,is_active`.
- `audit_log` table: `id,user_id,register_id,action,entity_type,entity_id,payload jsonb,ip,created_at`; indexes on `(entity_type,entity_id,created_at)` and `(user_id,created_at)`. Written via `DB::table` (deliberately no Eloquent model — the viewer reads via `DB::table` too).
- Middleware aliases live in `backend/bootstrap/app.php` (`device`, `staff`, `idempotent`); rate limiters in `AppServiceProvider` (`pin`, `api`, `catalog`).
- Pest helpers: `provisionedLocation()`, `registerAt()`, `staffWithRole()`, `staffHeaders()`; factories per model.
- Register app idioms to mirror in the new app: `frontend/web/next.config.ts` (rewrites + `typescript.ignoreBuildErrors`), `frontend/web/package.json` scripts, `src/lib/api.ts` envelope handling, per-file `@vitest-environment jsdom` pragma + `afterEach(cleanup)` in component tests.
- Config rule: engineers deploy config, admins change the database — new `pos.stock.low_threshold` goes in `config/pos.php`.

---

### Task 1: Admin auth — login, logout, EnsureAdmin, seeder credentials

**Files:**
- Create: `backend/app/Actions/Admin/AdminLogin.php`, `AdminLoginInput.php`, `AdminSession.php`
- Create: `backend/app/Actions/Admin/AdminLogout.php` (no Input needed — see step 4)
- Create: `backend/app/Http/Requests/Admin/AdminLoginRequest.php`
- Create: `backend/app/Http/Controllers/Admin/AdminLoginController.php`, `AdminLogoutController.php`
- Create: `backend/app/Http/Resources/Admin/AdminSessionResource.php`
- Create: `backend/app/Http/Middleware/EnsureAdmin.php`
- Create: `backend/app/Exceptions/Domain/InvalidCredentials.php`
- Modify: `backend/bootstrap/app.php` (alias `admin`), `backend/app/Providers/AppServiceProvider.php` (limiter `admin-login`), `backend/routes/api.php`, `backend/database/seeders/DatabaseSeeder.php`
- Test: `backend/tests/Feature/Admin/AdminLoginTest.php`

**Interfaces:**
- Produces: `POST /api/v1/admin/login {email, password}` → 200 `{data: {token, user: {id, name, email, is_admin}}}`; wrong email/password/inactive/non-admin → **401 `invalid_credentials`** (identical response for all four — no user enumeration); throttled 5/min/IP (`throttle:admin-login`, 429 beyond). `POST /api/v1/admin/logout` (authed) revokes the presented token → 204. Middleware alias `admin` = `EnsureAdmin` (user must be `is_admin` AND `is_active`, else 403 `forbidden` envelope). Route group for all later admin tasks:

```php
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function (): void {
    Route::post('/logout', AdminLogoutController::class)->name('admin.logout');
    // Tasks 2-7 add routes here
});
```

- Seeder: the existing admin user (Priya) gains `email => 'admin@pos.test'` and `password_hash => 'admin-dev-password'` (the `hashed` cast bcrypts it), printed alongside the dev PINs.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/Admin/AdminLoginTest.php
declare(strict_types=1);

use App\Models\User;

function adminUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'boss@pos.test',
        'password_hash' => 'secret-password',   // 'hashed' cast bcrypts on set
        'is_admin' => true,
        'is_active' => true,
    ], $attrs));
}

it('logs an admin in and the token works on an admin route', function (): void {
    $admin = adminUser();

    $response = $this->postJson('/api/v1/admin/login', [
        'email' => 'boss@pos.test', 'password' => 'secret-password',
    ])->assertOk()->assertJsonPath('data.user.is_admin', true);

    $token = $response->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty();

    $this->postJson('/api/v1/admin/logout', [], ['Authorization' => "Bearer {$token}"])
        ->assertNoContent();
    // revoked: the same token no longer authenticates
    $this->postJson('/api/v1/admin/logout', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.login', 'entity_id' => $admin->id]);
});

it('refuses wrong password, unknown email, inactive, and non-admin identically', function (): void {
    adminUser();
    adminUser(['email' => 'inactive@pos.test', 'is_active' => false]);
    adminUser(['email' => 'cashier@pos.test', 'is_admin' => false]);

    foreach ([
        ['email' => 'boss@pos.test', 'password' => 'wrong'],
        ['email' => 'nobody@pos.test', 'password' => 'secret-password'],
        ['email' => 'inactive@pos.test', 'password' => 'secret-password'],
        ['email' => 'cashier@pos.test', 'password' => 'secret-password'],
    ] as $attempt) {
        $this->postJson('/api/v1/admin/login', $attempt)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }
});

it('throttles the login route', function (): void {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/admin/login', ['email' => 'x@pos.test', 'password' => 'x']);
    }
    $this->postJson('/api/v1/admin/login', ['email' => 'x@pos.test', 'password' => 'x'])
        ->assertStatus(429);
});

it('EnsureAdmin refuses a non-admin bearer token with 403', function (): void {
    $staff = User::factory()->create(['email' => 'sup@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $token = $staff->createToken('test')->plainTextToken;
    $this->postJson('/api/v1/admin/logout', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});
```

> If `User::factory()` lacks defaults these attrs don't cover, mirror the factory idioms
> in `tests/Feature/Auth/StaffLoginTest.php`. If the register's device tokens make
> `auth:sanctum` resolve a Register instead of a User in some test path, note it —
> `EnsureAdmin` must reject non-User tokenables (`$request->user() instanceof User`).

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && DB_PORT=5433 ./vendor/bin/pest tests/Feature/Admin/AdminLoginTest.php`
Expected: FAIL — 404 route not defined.

- [ ] **Step 3: Exception, action, DTOs**

`InvalidCredentials.php` (the `OrderNotZero` mold, but 401 and NO details — enumeration is the threat):

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class InvalidCredentials extends DomainException
{
    public function __construct()
    {
        parent::__construct('The email or password is incorrect.');
    }

    public function errorCode(): string
    {
        return 'invalid_credentials';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    public function details(): array
    {
        return [];
    }
}
```

`AdminLoginInput.php`: readonly DTO `{ string $email, string $password, ?string $ip }`.
`AdminSession.php`: readonly DTO `{ User $user, string $token }`.

```php
<?php
// backend/app/Actions/Admin/AdminLogin.php
declare(strict_types=1);

namespace App\Actions\Admin;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\InvalidCredentials;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Back-office sign-in. One failure mode on purpose: wrong email, wrong password,
 * deactivated, and non-admin all answer identically, because a distinguishable
 * refusal is a user-enumeration oracle. Admin-only in v1 — supervisor access is a
 * named deferral in the spec (per-location team context doesn't belong on a
 * location-less surface).
 */
final class AdminLogin
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(AdminLoginInput $in): AdminSession
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($in->email)])->first();

        if ($user === null
            || ! $user->is_active
            || ! $user->is_admin
            || $user->password_hash === null
            || ! Hash::check($in->password, $user->password_hash)) {
            throw new InvalidCredentials;
        }

        $token = $user->createToken("admin:{$user->id}", ['admin']);

        $this->audit->record('admin.login', $user, $user->id, ip: $in->ip);

        return new AdminSession($user, $token->plainTextToken);
    }
}
```

`AdminLogout.php` — takes the User and the current access token id via a tiny readonly input or direct params; convention says Input DTO:

```php
<?php
// backend/app/Actions/Admin/AdminLogout.php
declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/** Revokes exactly the presented token — other sessions on other browsers survive. */
final class AdminLogout
{
    public function execute(User $user, string $tokenId): void
    {
        PersonalAccessToken::query()->whereKey($tokenId)->where('tokenable_id', $user->id)->delete();
    }
}
```

(If `tests/Arch` requires an Input DTO for every action, wrap the two params in
`AdminLogoutInput` — check how the arch test defines the rule before deviating.)

- [ ] **Step 4: Middleware, limiter, request, controllers, resource, routes, seeder**

`EnsureAdmin.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin gate: a bearer token whose owner is an active admin USER. Registers also
 * hold sanctum tokens (device tokens), so the instanceof check is load-bearing.
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_admin || ! $user->is_active) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Admin access required.', 'details' => []],
            ], 403);
        }

        return $next($request);
    }
}
```

(Match the exact error-envelope emission style of the existing middleware — read
`EnsureDeviceToken` first and mirror how it renders refusals; if it throws a
DomainException instead of building JSON inline, do that.)

- `bootstrap/app.php`: add `'admin' => EnsureAdmin::class` to the alias array.
- `AppServiceProvider`: `RateLimiter::for('admin-login', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));`
- `AdminLoginRequest`: `authorize(): true` (auth happens in the action); rules `['email' => ['required','email'], 'password' => ['required','string']]`; `toInput()` passes `$this->ip()`.
- `AdminLoginController`: `return (new AdminSessionResource($action->execute($request->toInput())))->response()->setStatusCode(200);`
- `AdminLogoutController`: `$request->user()->currentAccessToken()` gives the token model; call the action with its id; `return response()->noContent();`
- Routes (in `routes/api.php`, top level near enroll):

```php
Route::post('/admin/login', AdminLoginController::class)
    ->middleware('throttle:admin-login')
    ->name('admin.login');

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function (): void {
    Route::post('/logout', AdminLogoutController::class)->name('admin.logout');
});
```

- `AdminSessionResource`: `{'token' => $session->token, 'user' => ['id','name','email','is_admin' => ...]}`.
- Seeder: where Priya (admin) is created, add `'email' => 'admin@pos.test', 'password_hash' => 'admin-dev-password'`, and extend the printed credentials table with the email/password line.

- [ ] **Step 5: Run, then full suite**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Admin/AdminLoginTest.php && DB_PORT=5433 ./vendor/bin/pest`
Expected: PASS; 397 baseline intact + new.

- [ ] **Step 6: Commit**

```bash
git add -A backend && git commit -m "M6: admin auth — email+password bearer login, EnsureAdmin gate"
```

---

### Task 2: Catalog CRUD — categories, tax rates, products, variants

**Files:**
- Create: `backend/app/Actions/Admin/Catalog/` — `ListCategories.php`, `CreateCategory.php`, `UpdateCategory.php`, `CreateCategoryInput.php`, `UpdateCategoryInput.php`, and the same trio+DTOs for `TaxRate`, `Product`, `Variant` (list actions need no DTO)
- Create: matching `backend/app/Http/Requests/Admin/Catalog/*` and `backend/app/Http/Controllers/Admin/Catalog/*` (single-action, one per route)
- Create: `backend/app/Http/Resources/Admin/` — `AdminCategoryResource.php`, `AdminTaxRateResource.php`, `AdminProductResource.php`, `AdminVariantResource.php`
- Modify: `backend/routes/api.php` (inside the admin group), `backend/app/Models/ProductVariant.php` (add `is_active` to fillable if absent)
- Test: `backend/tests/Feature/Admin/CatalogCrudTest.php`

**Interfaces:**
- Produces, inside the admin group (NO DELETE routes — archive via PATCH `is_active`):

```php
Route::get('/categories', ListCategoriesController::class)->name('admin.categories.list');
Route::post('/categories', CreateCategoryController::class)->name('admin.categories.create');
Route::patch('/categories/{category}', UpdateCategoryController::class)->name('admin.categories.update');
// same GET/POST/PATCH triple for /tax-rates, /products, /variants
```

- Every mutation audits `admin.<entity>.create|update` with the changed fields in the payload. List responses: `{data: {items: [...]}}` — full sets, no pagination (a single business's catalog; the audit viewer is the only paginated list in M6).
- Field/rule matrix (validation in FormRequests; `sometimes` on PATCH so partial updates work):

| Entity | Fields (create) | Rules that matter |
| --- | --- | --- |
| Category | `name` req, `parent_id` nullable uuid exists:categories, `sort_order` int default 0 | parent must not create a self-cycle: `parent_id != $this->route('category')` on PATCH (one-level check is enough — the seeded tree is flat; note this in the request) |
| TaxRate | `name` req, `rate_micros` req int 0..1_000_000, `is_active` bool | micros, never floats |
| Product | `name` req, `description` nullable, `category_id` nullable uuid exists, `kind` in the model's existing kinds (read the CHECK in the migration; mirror it), `is_active` bool | — |
| Variant | `product_id` req uuid exists (create only — immutable on PATCH: `prohibited`), `name` req, `sku` req unique:product_variants,sku (ignore self on PATCH), `barcode` nullable unique likewise, `price_cents` req int min 0, `cost_cents` nullable int min 0, `tax_rate_id` nullable uuid exists, `track_inventory` bool, `is_active` bool | money integer cents; snapshots make repricing future-only automatically |

- Update actions: `findOrFail`, `fill` validated fields, save, audit with `array_keys($changes)` + old/new for money fields. No transactions needed beyond single-row writes EXCEPT audit+write must share one — wrap each execute in `DB::transaction` like every other action.

- [ ] **Step 1: Write the failing tests** — one file covering the pattern once per entity plus the rules with teeth:

```php
<?php
// backend/tests/Feature/Admin/CatalogCrudTest.php
declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;

beforeEach(function (): void {
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

it('creates, lists, and archives a product end to end', function (): void {
    $create = $this->postJson('/api/v1/admin/products', ['name' => 'Flat White', 'kind' => 'food_service'], $this->headers)
        ->assertCreated();
    $id = $create->json('data.product.id');

    $this->patchJson("/api/v1/admin/products/{$id}", ['is_active' => false], $this->headers)->assertOk();
    expect(Product::findOrFail($id)->is_active)->toBeFalse();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.product.update', 'entity_id' => $id]);
});

it('archived variants leave the register catalog but stay resolvable', function (): void {
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 500]);
    $this->patchJson("/api/v1/admin/variants/{$variant->id}", ['is_active' => false], $this->headers)->assertOk();

    // register catalog no longer carries it (GetCatalog filters is_active)
    $location = provisionedLocation();
    $register = registerAt($location);
    $token = $register->createToken('device')->plainTextToken;
    $catalog = $this->getJson('/api/v1/catalog', ['Authorization' => "Bearer {$token}"])->json('data');
    expect(collect($catalog['variants'])->pluck('id'))->not->toContain($variant->id);
    // but the row still exists for receipts/refunds
    expect(ProductVariant::withoutGlobalScopes()->find($variant->id))->not->toBeNull();
});

it('enforces sku uniqueness except against itself', function (): void {
    $a = ProductVariant::factory()->untracked()->create(['sku' => 'SKU-1']);
    $b = ProductVariant::factory()->untracked()->create(['sku' => 'SKU-2']);
    $this->patchJson("/api/v1/admin/variants/{$b->id}", ['sku' => 'SKU-1'], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
    $this->patchJson("/api/v1/admin/variants/{$a->id}", ['sku' => 'SKU-1'], $this->headers)->assertOk();
});

it('refuses DELETE verbs everywhere — archive is the only removal', function (): void {
    $variant = ProductVariant::factory()->untracked()->create();
    $this->deleteJson("/api/v1/admin/variants/{$variant->id}", [], $this->headers)->assertStatus(405);
});

it('rejects a category as its own parent', function (): void {
    $cat = $this->postJson('/api/v1/admin/categories', ['name' => 'Drinks'], $this->headers)->json('data.category.id');
    $this->patchJson("/api/v1/admin/categories/{$cat}", ['parent_id' => $cat], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('keeps tax rates in micros and refuses out-of-range', function (): void {
    $this->postJson('/api/v1/admin/tax-rates', ['name' => 'VAT', 'rate_micros' => 1_000_001], $this->headers)
        ->assertStatus(400);
    $this->postJson('/api/v1/admin/tax-rates', ['name' => 'VAT', 'rate_micros' => 200_000], $this->headers)
        ->assertCreated()->assertJsonPath('data.tax_rate.rate_micros', 200000);
});

it('a non-admin token gets 403 on every admin catalog route', function (): void {
    $staff = User::factory()->create(['email' => 's@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];
    $this->getJson('/api/v1/admin/products', $headers)->assertStatus(403);
});
```

> `kind` values: read the products migration CHECK before writing the test — use a real
> one. If `ProductVariant` has no `is_active` column (check the migration), the archive
> test target is wrong — STOP and report NEEDS_CONTEXT rather than inventing a column.

- [ ] **Step 2: Run to verify failure** — `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Admin/CatalogCrudTest.php` → 404s.

- [ ] **Step 3: Implement one entity completely (Product), then stamp the pattern.** The Product trio, in full:

```php
<?php
// backend/app/Actions/Admin/Catalog/CreateProduct.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class CreateProduct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreateProductInput $in): Product
    {
        return DB::transaction(function () use ($in): Product {
            $product = Product::create([
                'name' => $in->name,
                'description' => $in->description,
                'category_id' => $in->categoryId,
                'kind' => $in->kind,
                'is_active' => true,
            ]);

            $this->audit->record('admin.product.create', $product, $in->actorId, [
                'name' => $in->name, 'kind' => $in->kind,
            ]);

            return $product;
        });
    }
}
```

```php
<?php
// backend/app/Actions/Admin/Catalog/UpdateProduct.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class UpdateProduct
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateProductInput $in): Product
    {
        return DB::transaction(function () use ($in): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($in->productId);

            $product->fill($in->changes)->save();

            $this->audit->record('admin.product.update', $product, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $product;
        });
    }
}
```

`UpdateProductInput`: `{ string $productId, array $changes, string $actorId }` — `changes` is the FormRequest's `validated()` array filtered to present keys (PATCH semantics: `$this->safe()->only([...])` of the fields actually sent). `ListProducts`: `Product::query()->orderBy('name')->get()` (include archived — the back office sees everything; the register catalog is what filters). Requests authorize `Permissions::CATALOG_MANAGE`. Controllers: 201 for create wrapping `{'product' => resource}`, 200 for update/list (`{'items' => [...]}`).

Stamp the same trio for Category, TaxRate, Variant with the rule matrix above. For money fields on Variant updates, audit old→new: `['price_cents' => ['from' => $old, 'to' => $new]]` when price changed — repricing is the fraud-adjacent event worth the richer payload.

- [ ] **Step 4: Run, full suite, commit**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Admin/CatalogCrudTest.php && DB_PORT=5433 ./vendor/bin/pest`

```bash
git add -A backend && git commit -m "M6: catalog CRUD — categories, tax rates, products, variants; archive never delete"
```

---

### Task 3: Catalog CRUD — modifier groups, modifiers, discounts, product↔group attach

**Files:**
- Create: `backend/app/Actions/Admin/Catalog/` trios+DTOs for `ModifierGroup`, `Modifier`, `Discount`; plus `SetProductModifierGroups.php` + Input
- Create: matching Requests/Controllers/Resources under the Task-2 namespaces
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/ModifierDiscountCrudTest.php`

**Interfaces:**
- Routes (admin group): GET/POST/PATCH triples for `/modifier-groups`, `/modifiers`, `/discounts`, plus `PUT /products/{product}/modifier-groups {group_ids: [uuid,...]}` (full-set replace of the pivot, ordered — position = array index). Audits `admin.modifier_group.*`, `admin.modifier.*`, `admin.discount.*`, `admin.product.modifier_groups`.
- Rules with teeth: ModifierGroup `min_select` int ≥0, `max_select` nullable int, cross-field `max_select >= min_select` when both present (the DB CHECK backs it; the request refuses first with 400); Modifier `price_delta_cents` int (may be negative — the sign is the meaning), `group_id` immutable on PATCH (`prohibited`); Discount kind/percent/amount cross-rules exactly mirroring the DB CHECK (`percent` ⇒ `percent_micros` required + `amount_cents` prohibited; `fixed` ⇒ inverse), `scope` in order|line, `requires_supervisor` bool stored as-is (enforcement unchanged — spec defers the relaxation).
- Tightening a group's `min_select`/`max_select` affects only future add-line validation — lines already on orders carry frozen snapshots. State this in the UpdateModifierGroup doc-comment.

- [ ] **Step 1: Failing tests** — same idiom as Task 2's file: create/update/audit per entity; `max_select < min_select` → 400; percent discount with `amount_cents` → 400; negative `price_delta_cents` accepted; the PUT attach: create product + two groups, PUT `[g2, g1]`, assert pivot order (position 0 = g2), PUT `[g1]` removes g2; attach to a product with existing order lines still works (frozen snapshots unaffected — assert an existing line's `modifiers_total_cents` unchanged after a delta edit).
- [ ] **Step 2: Verify failure.** `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Admin/ModifierDiscountCrudTest.php` → 404s.
- [ ] **Step 3: Implement** — stamp the Task-3 trios from the Task-2 Product exemplar (same transaction+audit shape, same PATCH `changes` semantics). `SetProductModifierGroups`:

```php
<?php
// backend/app/Actions/Admin/Catalog/SetProductModifierGroups.php
declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** Full-set replace; position = array index. Empty array detaches everything. */
final class SetProductModifierGroups
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(SetProductModifierGroupsInput $in): Product
    {
        return DB::transaction(function () use ($in): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($in->productId);

            $sync = [];
            foreach (array_values($in->groupIds) as $ix => $groupId) {
                $sync[$groupId] = ['position' => $ix];
            }
            $product->modifierGroups()->sync($sync);

            $this->audit->record('admin.product.modifier_groups', $product, $in->actorId, [
                'group_ids' => $in->groupIds,
            ]);

            return $product->load('modifierGroups');
        });
    }
}
```

Request rules: `['group_ids' => ['present', 'array'], 'group_ids.*' => ['uuid', 'exists:modifier_groups,id']]`.

- [ ] **Step 4: Run, full suite, commit**

```bash
git add -A backend && git commit -m "M6: modifier-group, modifier, discount CRUD + product attach"
```

---

### Task 4: User management — create/update staff, roles, self-lockout guard

**Files:**
- Create: `backend/app/Actions/Admin/Users/ListUsers.php`, `CreateUser.php`, `CreateUserInput.php`, `UpdateUser.php`, `UpdateUserInput.php`
- Create: `backend/app/Http/Requests/Admin/Users/CreateUserRequest.php`, `UpdateUserRequest.php`; controllers; `backend/app/Http/Resources/Admin/AdminUserResource.php`
- Create: `backend/app/Exceptions/Domain/SelfLockout.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/UserManagementTest.php`

**Interfaces:**
- `GET /api/v1/admin/users` → `{items: [{id, name, email, is_admin, is_active, roles: [{location_id, location_name, role}]}]}` — roles read via a direct `model_has_roles` join (NEVER the spatie relation).
- `POST /api/v1/admin/users {name, email?, password?, pin?, is_admin?, roles?: [{location_id, role: 'cashier'|'supervisor'}]}` → 201. Schema CHECK needs email-or-pin: request enforces `required_without` both ways (400 before the DB would 500). PIN set via the existing `SetStaffPin` action (collision check + HMAC lookup come free); create the user first (transaction), then call SetStaffPin inside the same flow — SetStaffPin opens its own transaction; nested transactions become savepoints, which is fine and already how idempotency wraps actions.
- `PATCH /api/v1/admin/users/{user} {name?, email?, password?, pin?, is_admin?, is_active?, roles?}` → 200. `roles` is full-set replace per location (delete this user's `model_has_roles` rows, insert the new set — role ids resolved from the `roles` table by name+`web` guard). **Self-lockout guard**: the acting admin cannot set their own `is_admin => false` or `is_active => false` → 422 `self_lockout` (`SelfLockout` exception, details `{user_id}`).
- Deactivation revokes the user's staff sessions? NO — out of scope; sessions expire on TTL and PIN login refuses inactive users already (verify: StaffLogin checks `is_active`; if it doesn't, add the check there — it's a one-line security fix worth folding in, note it in the report).
- Audits: `admin.user.create`, `admin.user.update` (changed keys; `roles` changes logged as `{location_id, from, to}` rows), plus SetStaffPin's own `staff.pin.set` (already exists).

- [ ] **Step 1: Failing tests** — create PIN-only staff with a cashier role at one location (assert `model_has_roles` row + can PIN-login at that location's register); create email-only admin; neither email nor pin → 400; role replace (cashier→supervisor at loc A, assert old row gone); self-de-admin → 422 `self_lockout`; self-deactivate → 422; deactivating ANOTHER admin → 200; PIN collision surfaces as 422 `pin_already_in_use` (existing exception) through the create endpoint; non-admin token → 403.
- [ ] **Step 2: Verify failure.**
- [ ] **Step 3: Implement.** `CreateUser` core:

```php
<?php
// backend/app/Actions/Admin/Users/CreateUser.php
declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Actions\Auth\SetStaffPin;
use App\Actions\Auth\SetStaffPinInput;
use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    public function __construct(
        private readonly SetStaffPin $setPin,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CreateUserInput $in): User
    {
        return DB::transaction(function () use ($in): User {
            $user = User::create([
                'name' => $in->name,
                'email' => $in->email,
                'password_hash' => $in->password,   // 'hashed' cast bcrypts; null stays null
                'is_admin' => $in->isAdmin,
                'is_active' => true,
            ]);

            $this->syncRoles($user, $in->roles);

            if ($in->pin !== null) {
                // Roles must exist first: SetStaffPin scopes its collision check to the
                // user's locations, which come from model_has_roles.
                $this->setPin->execute(new SetStaffPinInput($user->id, $in->pin, $in->actorId));
            }

            $this->audit->record('admin.user.create', $user, $in->actorId, [
                'name' => $in->name, 'is_admin' => $in->isAdmin,
                'roles' => $in->roles,
            ]);

            return $user->refresh();
        });
    }

    /** @param list<array{location_id: string, role: string}> $roles */
    private function syncRoles(User $user, array $roles): void
    {
        DB::table('model_has_roles')
            ->where('model_type', $user->getMorphClass())
            ->where('model_uuid', $user->id)
            ->delete();

        foreach ($roles as $assignment) {
            $roleId = DB::table('roles')->where('name', $assignment['role'])->value('id');
            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => $user->getMorphClass(),
                'model_uuid' => $user->id,
                'location_id' => $assignment['location_id'],
            ]);
        }
    }
}
```

> The `model_has_roles` column names (`model_uuid` vs `model_id`, team column name) were
> customized in M2's published migration — READ
> `backend/database/migrations/*create_permission_tables*.php` first and use the real
> names. `UpdateUser` reuses `syncRoles` (extract to a small shared
> `App\Domain\Rbac\RoleAssignments` class rather than duplicating — two callers, real
> logic, worth one home) plus the SelfLockout guard:

```php
if ($in->userId === $in->actorId && (($in->changes['is_admin'] ?? true) === false || ($in->changes['is_active'] ?? true) === false)) {
    throw new SelfLockout($in->userId);
}
```

Request rules (create): `name` required; `email` nullable email unique (lower) — use `Rule::unique('users', 'email')->ignore(...)` on PATCH; `password` nullable string min 10 `required_with:email`... no — email-only users with no password can't log in anywhere yet are legal rows (a bookkeeper-to-be); keep `password` nullable, independent; `pin` nullable digits 4-6; cross rule: `required_without:pin` on email and `required_without:email` on pin; `roles` array of `{location_id: uuid exists:locations,id, role: in:cashier,supervisor}`.

- [ ] **Step 4: Run, full suite, commit**

```bash
git add -A backend && git commit -m "M6: user management — staff creation, direct role writes, self-lockout guard"
```

---

### Task 5: Locations & registers — settings CRUD, mode, token reissue, open-shifts endpoint

**Files:**
- Create: `backend/app/Actions/Admin/Locations/ListLocations.php`, `CreateLocation.php`, `UpdateLocation.php` (+DTOs); `backend/app/Actions/Admin/Registers/ListRegisters.php`, `CreateRegister.php`, `UpdateRegister.php`, `ReissueDeviceToken.php` (+DTOs)
- Create: `backend/app/Actions/Shifts/ListOpenShiftRegisters.php`
- Create: matching Requests/Controllers/Resources; `backend/app/Http/Controllers/Shifts/OpenShiftRegistersController.php` + request
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/LocationRegisterTest.php`, `backend/tests/Feature/Shifts/OpenShiftRegistersTest.php`

**Interfaces:**
- Admin group: GET/POST/PATCH triples for `/locations` (fields: `name` req, `timezone` req valid tz (`Rule::in(timezone_identifiers_list())` or `['timezone']` rule), `prices_include_tax` bool, `receipt_header`/`receipt_footer` nullable — read the locations migration for exact column names first) and `/registers` (fields: `location_id` req on create/immutable on PATCH, `name` req unique per location, `mode` in retail|food, `is_active` bool).
- `POST /api/v1/admin/registers/{register}/token` → 201 `{token}` — `ReissueDeviceToken`: inside a transaction, delete the register's existing sanctum tokens, mint a fresh `device:{id}` token (same shape as `EnrollRegister`), audit `admin.register.token_reissue`. The old till goes dark immediately — that's the point (lost/stolen terminal).
- Existing `POST /registers/enroll` stays as-is (creates row + first token); the admin UI uses `CreateRegister` + reissue OR enroll — the UI decides; both are legal.
- **Fold-in (staff tier, register app consumes it):** `GET /api/v1/registers/open-shifts` (inside the existing `staff` middleware group) → `{items: [{register_id, register_name, shift_id, opened_by_name}]}` for the acting register's location — registers with an open shift, EXCLUDING the acting register itself. One query: registers where `is_active` joined to open shifts (`closed_at is null`) + opener name.

- [ ] **Step 1: Failing tests** — location create/update with tz validation (bad tz → 400); register mode flip audits; register name unique per location but reusable across locations; token reissue: old token 401s afterward, new token passes `device` middleware (hit `/api/v1/catalog` with each); open-shifts: two registers with open shifts + one closed + one other-location → acting register sees exactly the one other open register with its opener's name; requires staff session (device-only → 401).
- [ ] **Step 2: Verify failure.**
- [ ] **Step 3: Implement.** `ReissueDeviceToken` core:

```php
public function execute(ReissueDeviceTokenInput $in): EnrolledRegister
{
    return DB::transaction(function () use ($in): EnrolledRegister {
        $register = Register::query()->lockForUpdate()->findOrFail($in->registerId);

        $register->tokens()->delete();
        $token = $register->createToken("device:{$register->id}", ['device']);

        $this->audit->record('admin.register.token_reissue', $register, $in->actorId);

        return new EnrolledRegister($register, $token->plainTextToken);
    });
}
```

`ListOpenShiftRegisters::execute(string $actingRegisterId): Collection` — resolve the acting register's location, then:

```php
return DB::table('registers as r')
    ->join('shifts as s', fn ($j) => $j->on('s.register_id', '=', 'r.id')->whereNull('s.closed_at'))
    ->join('users as u', 'u.id', '=', 's.opened_by')
    ->where('r.location_id', $locationId)
    ->where('r.is_active', true)
    ->where('r.id', '!=', $actingRegisterId)
    ->orderBy('r.name')
    ->get(['r.id as register_id', 'r.name as register_name', 's.id as shift_id', 'u.name as opened_by_name']);
```

- [ ] **Step 4: Run, full suite, commit**

```bash
git add -A backend && git commit -m "M6: location/register settings, device-token reissue, open-shifts endpoint"
```

---

### Task 6: Reports — sales (day|category|user), stock, low threshold

**Files:**
- Create: `backend/app/Actions/Admin/Reports/SalesReport.php`, `SalesReportInput.php`, `StockReport.php`, `StockReportInput.php`
- Create: `backend/app/Http/Requests/Admin/Reports/SalesReportRequest.php`, `StockReportRequest.php`; controllers; `backend/app/Http/Resources/Admin/SalesReportResource.php`, `StockReportResource.php`
- Modify: `backend/routes/api.php`, `backend/config/pos.php` (`'stock' => ['low_threshold' => 5]`)
- Test: `backend/tests/Feature/Admin/ReportsTest.php`

**Interfaces:**
- `GET /api/v1/admin/reports/sales?from=YYYY-MM-DD&to=YYYY-MM-DD&location_id=&group_by=day|category|user` (all four required; range ≤ 366 days else 400). Response `{rows: [...], totals: {...}}`:
  - `group_by=day`: rows `{bucket: '2026-07-18', orders_closed, gross_cents, refunds_cents, net_cents}` — **from the ledgers**: gross = captured payments joined to orders on `business_date` + location; refunds by their own `business_date` + location; orders_closed = closed orders that date. Voided orders never enter (payments on them were voided/refunded per M4 rules; the join is on payments, and split originals have none).
  - `group_by=user`: same money columns keyed by `payments.user_id` (gross) / `refunds.user_id` (refunds), `{bucket: user name}`.
  - `group_by=category`: **line-based sales mix, not ledger money** — non-voided lines of closed orders in range: `{bucket: category name (or 'Uncategorized'), qty_sold: '12.500', line_total_cents}`. Reporting joins MAY read the live catalog (variant→product→category); receipts may not. Say so in the resource doc-comment, and the response carries `"basis": "lines"` vs `"basis": "ledger"` so the UI can label the difference.
- `GET /api/v1/admin/reports/stock?location_id=&low_only=true|false` → rows `{variant_id, sku, name, qty: '4.000', low: bool}` — reads `stock_levels` joined to variants; `low = qty <= config('pos.stock.low_threshold')`. `low_only` filters.
- Both audit nothing (reads), require admin (group membership does it), and are location-scoped by the required `location_id`.

- [ ] **Step 1: Failing tests** — seed a deterministic day via existing actions (open shift → sale 1000¢ cash by cashier A → sale 500¢ card by cashier B → refund 300¢ by B), then: `day` rows sum gross 1500 / refunds 300 / net 1200; `user` attributes 1000 to A and 500−300 to B; `category` buckets the two variants' categories with exact `line_total_cents`; a VOIDED order's line never appears in `category` and its (voided) payment never in `day`; stock report `low` flips at the config threshold (set `config(['pos.stock.low_threshold' => 5])` in-test); range > 366 days → 400; missing `group_by` → 400.
- [ ] **Step 2: Verify failure.**
- [ ] **Step 3: Implement.** `SalesReport::execute` switches on `groupBy` into three private query methods; day exemplar:

```php
private function byDay(SalesReportInput $in): array
{
    $gross = DB::table('payments as p')
        ->join('orders as o', 'o.id', '=', 'p.order_id')
        ->where('p.status', 'captured')
        ->where('o.location_id', $in->locationId)
        ->whereBetween('o.business_date', [$in->from, $in->to])
        ->groupBy('o.business_date')
        ->selectRaw('o.business_date as bucket, sum(p.amount_cents) as gross_cents')
        ->pluck('gross_cents', 'bucket');

    $refunds = DB::table('refunds')
        ->where('location_id', $in->locationId)
        ->whereBetween('business_date', [$in->from, $in->to])
        ->groupBy('business_date')
        ->selectRaw('business_date as bucket, sum(amount_cents) as refunds_cents')
        ->pluck('refunds_cents', 'bucket');

    $ordersClosed = DB::table('orders')
        ->where('location_id', $in->locationId)
        ->where('status', 'closed')
        ->whereBetween('business_date', [$in->from, $in->to])
        ->groupBy('business_date')
        ->selectRaw('business_date as bucket, count(*) as n')
        ->pluck('n', 'bucket');

    $buckets = collect($gross->keys())->merge($refunds->keys())->merge($ordersClosed->keys())->unique()->sort();

    return $buckets->map(fn ($day) => [
        'bucket' => (string) $day,
        'orders_closed' => (int) ($ordersClosed[$day] ?? 0),
        'gross_cents' => (int) ($gross[$day] ?? 0),
        'refunds_cents' => (int) ($refunds[$day] ?? 0),
        'net_cents' => (int) ($gross[$day] ?? 0) - (int) ($refunds[$day] ?? 0),
    ])->values()->all();
}
```

(All integer casts explicit — pgsql `sum()` returns strings.) `user` mirrors it keyed by user id then maps names in one `whereIn` query; `category` joins `order_lines → orders(status=closed, range, location) → product_variants → products → categories`, `whereNull('order_lines.voided_at')`, summing `line_total_cents` and `qty`.

- [ ] **Step 4: Run, full suite, commit**

```bash
git add -A backend && git commit -m "M6: sales and stock reports off the ledgers, config low-stock threshold"
```

---

### Task 7: Audit viewer + Z-report split annotation + relation cleanups

**Files:**
- Create: `backend/app/Actions/Admin/Audit/ListAuditLog.php`, `ListAuditLogInput.php`; request/controller/`AdminAuditResource.php`
- Modify: `backend/routes/api.php`; `backend/app/Actions/Reports/GetZReport.php` (+ its resource + register `ZReport` type later in Task 10); `backend/app/Models/Order.php` + the files using `openedBy()`/`opener()` (consolidate); `backend/app/Http/Resources/` (rename the shared `{order}` envelope out of `VoidOrderResource`)
- Test: `backend/tests/Feature/Admin/AuditViewerTest.php`; additions to `backend/tests/Feature/Reports/ZReportTest.php` (file name per existing suite)

**Interfaces:**
- `GET /api/v1/admin/audit?entity_type=&entity_id=&user_id=&action=&from=&to=&page=1` → `{rows: [{id, created_at, action, entity_type, entity_id, user_name, register_name, payload}], page, has_more}` — `DB::table('audit_log')` with left joins to users/registers for names, all filters optional (dates are `created_at` date bounds), `orderByDesc('created_at')`, fixed `per_page = 50` (`limit 51`, `has_more = count > 50`, return 50). The two existing indexes cover the entity and user filter paths.
- Z-report: `orders_voided` becomes genuine voids only (`where('void_reason', 'not like', 'split into%')` — or `orders_split` counted separately and emitted as its own field; do BOTH: `orders_voided` excludes splits, new `orders_split` counts them). Extend the existing Z test with a split + a real void asserting the two fields.
- Cleanups: keep `Order::opener()` (used by M5 resources), delete `openedBy()` after repointing its callers (grep first — `grep -rn 'openedBy' backend/`); create `backend/app/Http/Resources/OrderEnvelopeResource.php` with `VoidOrderResource`'s exact body, repoint `SetTableRefController` + `TransferOrderController` + `VoidOrderController` to it, delete `VoidOrderResource`. Suite green proves the repoint.

- [ ] **Step 1: Failing tests** — audit list: seed rows via real actions (a void, a discount), filter by `entity_type=Order` returns only order rows with `user_name` resolved; `user_id` filter; `action` filter; pagination `has_more` with 51 rows (loop `AuditLogger::record` directly — it's a domain class, fine to call in tests); non-admin 403. Z: run a split and a genuine void in one shift → `orders_voided = 1`, `orders_split = 1`.
- [ ] **Step 2: Verify failure.**
- [ ] **Step 3: Implement** (ListAuditLog is one chained query with `when()` filters, matching the exemplars above; Z change is a two-line query edit + resource field).
- [ ] **Step 4: Run, full suite, commit**

```bash
git add -A backend && git commit -m "M6: audit viewer, Z-report split annotation, envelope/relation cleanups"
```

### Task 8: Back-office app scaffold — Next app, admin client, login, shell

**Files:**
- Create: `frontend/back-office/package.json`, `next.config.ts`, `tsconfig.json`, `app/layout.tsx`, `app/page.tsx`, `app/providers.tsx`
- Create: `frontend/back-office/src/lib/api.ts`, `src/lib/api.test.ts`, `src/lib/money.ts` (copy from `frontend/web/src/lib/money.ts` verbatim, including its test file), `src/index.css` + `src/styles/tokens.css` (copy from `frontend/web`, then adapt density)
- Create: `frontend/back-office/src/admin/AdminApp.tsx`, `src/admin/LoginScreen.tsx`, `src/admin/Shell.tsx`
- Test: `frontend/back-office/src/lib/api.test.ts`, `src/admin/LoginScreen.test.tsx`

**Interfaces:**
- `package.json`: copy `frontend/web`'s scripts/deps changing `"name": "back-office"` and ports — `"dev": "next dev -p 5175"`, `"start": "next start -p 5175"`. Same devDeps (vitest, @testing-library, jsdom, oxlint, TS 7.0.2). `next.config.ts`: identical to the register app's (rewrites to `http://127.0.0.1:8000`, `typescript.ignoreBuildErrors` with the same TS7 comment).
- `src/lib/api.ts` — fresh admin client (do NOT copy the register's), same envelope discipline:

```ts
export type AdminUser = { id: string; name: string; email: string | null; is_admin: boolean }
export type AdminSession = { token: string; user: AdminUser }
export class ApiError extends Error { readonly code: string; readonly status: number; readonly details: Record<string, unknown> /* same ctor as register app */ }
export const adminToken = {
  get: () => localStorage.getItem('pos.admin_token'),
  set: (t: string) => localStorage.setItem('pos.admin_token', t),
  clear: () => localStorage.removeItem('pos.admin_token'),
}
// request<T>(path, init?) — same {data}/{error} unwrapping as the register client, but
// Authorization: Bearer <adminToken> and no device/staff headers.
export const api = {
  login: async (email: string, password: string): Promise<AdminSession> => { /* posts, stores token */ },
  logout: async (): Promise<void> => { /* best-effort post, clears token */ },
  // Tasks 9-11 extend from here:
  // categories/taxRates/products/variants/modifierGroups/modifiers/discounts:
  //   list<T>(): Promise<T[]>; create(body): Promise<T>; update(id, body): Promise<T>
  // setProductModifierGroups(productId, groupIds: string[])
  // users: list/create/update per Task 4 shapes
  // locations, registers (+ reissueToken(registerId): Promise<string>)
  // salesReport(params): Promise<SalesReport>; stockReport(params)
  // audit(params): Promise<AuditPage>
}
```

- `AdminApp` stage machine (mirrors the register's booting gate): `booting` (localStorage after mount) → `login` → `shell`; 401 from any query/mutation → `adminToken.clear()` + back to login (register-app convention). `Shell` renders the carbon bar (POS · BACK OFFICE) + nav rail — Catalog / Users / Locations & Registers / Reports / Audit — and a content slot; nav state is a `useState<Section>`, no router pages needed beyond the single client boundary (same one-page architecture as the register).
- CSS: copied tokens; add a `.bo-table` density class (13px rows, plate-framed tables), keep uppercase 11px labels, warm color = one primary action per screen. No 44px mandate.

- [ ] **Step 1: Failing tests** — api.test.ts (register-app harness idiom, stub fetch): login stores the token and unwraps `{data}`; 401 envelope → `ApiError` with code; logout clears even when the request fails. LoginScreen.test.tsx (jsdom pragma + cleanup): renders email/password, submit calls `api.login`, error envelope shows the message, success calls `onLoggedIn`.
- [ ] **Step 2: Verify failure** — `cd frontend/back-office && npm install && npm test` → failures (files missing).
- [ ] **Step 3: Implement** the scaffold exactly per the interfaces; `vitest.config` mirrors the register app's (check `frontend/web` for whether config lives in `vite.config`/`vitest.config`/package — mirror it).
- [ ] **Step 4: Gates** — `npm test && npm run typecheck && npm run build` all green.
- [ ] **Step 5: Commit**

```bash
git add frontend/back-office && git commit -m "M6: back-office app scaffold — admin client, login, shell"
```

---

### Task 9: Catalog screens

**Files:**
- Create: `frontend/back-office/src/admin/catalog/CatalogSection.tsx`, `EntityTable.tsx`, `ProductEditor.tsx`, `VariantEditor.tsx`, `ModifierGroupEditor.tsx`, `DiscountEditor.tsx`, `SimpleEditor.tsx` (categories/tax-rates)
- Modify: `src/lib/api.ts` (catalog CRUD methods per Task 8's comment block), `src/index.css`
- Test: `frontend/back-office/src/admin/catalog/EntityTable.test.tsx`, `ProductEditor.test.tsx`

**Interfaces:**
- `CatalogSection` = tab rail (Products / Variants / Categories / Modifier groups / Discounts / Tax rates) over a shared `EntityTable` (generic: columns def + rows + onEdit + ARCHIVED badge for `is_active: false` + NEW button) and per-entity editor plates.
- Editors are controlled forms → `useMutation` → on success invalidate that entity's list query. Money fields use `parseCentsOrNull`-style validation from the copied `money.ts` (cents in/out — never floats); tax rates edit in **percent presented, micros stored** (`rate_micros / 10_000` display, `Math.round(pct * 10_000)` on save — display only, server owns truth). Archive button behind a confirm (`window.confirm` is fine — laptop surface).
- `ProductEditor` includes the modifier-group attach list (ordered checkboxes → `setProductModifierGroups`). `VariantEditor` includes SKU/barcode/price/track_inventory/tax-rate select.
- Every list shows archived rows greyed with an UNARCHIVE action (PATCH `is_active: true`).

- [ ] **Step 1: Failing tests** — EntityTable renders rows + archived badge + fires onEdit; ProductEditor: save calls `api.products.update` with only changed fields (PATCH semantics), attach list calls `setProductModifierGroups` with ordered ids.
- [ ] **Step 2: Verify failure.** `npm test`.
- [ ] **Step 3: Implement** (client methods + screens; keep each editor < ~150 lines, shared field components where repetition appears — a `MoneyField` used by Variant and Discount editors).
- [ ] **Step 4: Gates + commit**

```bash
git add frontend/back-office && git commit -m "M6: catalog screens — tables, editors, attach, archive"
```

---

### Task 10: Users + Locations & Registers screens; register-app transfer picker fix

**Files:**
- Create: `frontend/back-office/src/admin/users/UsersSection.tsx`, `UserEditor.tsx`; `src/admin/places/PlacesSection.tsx`, `LocationEditor.tsx`, `RegisterEditor.tsx`
- Modify: `frontend/back-office/src/lib/api.ts` (users/locations/registers methods incl. `reissueToken`)
- Modify (register app): `frontend/web/src/lib/api.ts` (`openShiftRegisters(): Promise<Array<{register_id, register_name, shift_id, opened_by_name}>>` hitting `/registers/open-shifts`), `frontend/web/src/register/FloorScreen.tsx` (transfer picker sources targets from it instead of inferring from open orders), `frontend/web/src/register/FloorScreen.test.tsx` (update the transfer tests' mocks)
- Test: `frontend/back-office/src/admin/users/UserEditor.test.tsx`; register app's updated FloorScreen tests

**Interfaces:**
- `UserEditor`: name/email/password/PIN fields (PIN + email cross-requirement mirrored client-side as UX), role rows (location select + cashier/supervisor), is_admin + is_active toggles; self-lockout 422 renders the server message. Deactivated users greyed in the table, never removed.
- `RegisterEditor`: name, mode (RETAIL / FOOD chips), active toggle, and REISSUE TOKEN — confirm dialog warning the till goes dark, then shows the new token **once** in a copy-me plate (never stored client-side beyond the modal's state).
- `LocationEditor`: name, timezone (datalist of `Intl.supportedValuesOf('timeZone')`), prices_include_tax toggle (with a "future orders only" note), receipt header/footer.
- Register app: `FloorScreen`'s transfer picker lists `openShiftRegisters()` results (label: `register_name — opened_by_name`), enabling transfer to tabless registers (the M5 gap); polling stays on open-orders for the cards themselves.

- [ ] **Step 1: Failing tests** — UserEditor submits only changed fields; 422 self_lockout message rendered. FloorScreen: transfer picker shows a register that has an open shift but NO open orders (the exact M5 gap, now the regression test).
- [ ] **Step 2: Verify failure** (both apps' `npm test`).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Gates on BOTH apps** — back-office `npm test && npm run typecheck && npm run build`; register `cd frontend/web && npm test && npm run typecheck && npm run build`.
- [ ] **Step 5: Commit**

```bash
git add frontend/back-office frontend/web && git commit -m "M6: users + places screens, token reissue, transfer picker on open-shifts"
```

---

### Task 11: Reports + audit screens, CSV export

**Files:**
- Create: `frontend/back-office/src/admin/reports/ReportsSection.tsx`, `SalesReportView.tsx`, `StockReportView.tsx`, `src/admin/audit/AuditSection.tsx`, `src/lib/csv.ts`
- Modify: `frontend/back-office/src/lib/api.ts` (report/audit methods + types per Task 6/7 response shapes)
- Test: `frontend/back-office/src/lib/csv.test.ts`, `src/admin/reports/SalesReportView.test.tsx`

**Interfaces:**
- `SalesReportView`: date-range inputs (default: last 7 days), location select, group-by chips (DAY / CATEGORY / USER), table of rows + a totals row, money via the copied `formatMoney`; a `basis` label ("ledger" vs "line-based sales mix" — the Task 6 field) so the two report kinds aren't silently conflated; EXPORT CSV button.
- `csv.ts`: `toCsv(headers: string[], rows: Array<Array<string | number>>): string` — quotes fields containing `",\n`, doubles inner quotes, joins with `\r\n`; download via a Blob + anchor click. Money exported as **decimal strings** (`(cents / 100).toFixed(2)`) — the one place display-formatting is allowed to leave the app, and it's presentation, not arithmetic.
- `StockReportView`: location select + LOW ONLY toggle; low rows flagged with the warm accent (it's the report's one action-worthy signal).
- `AuditSection`: filter bar (entity type select over the known set, entity id, user, action, from/to), 50-row pages with LOAD MORE (`has_more`), payload rendered as collapsed `<details>` JSON.

- [ ] **Step 1: Failing tests** — `csv.test.ts`: quoting (`a,"b"` → `"a,""b"""`), CRLF join, decimal money strings; SalesReportView: group-by switch refetches with the right params (mock api), totals row sums the mocked rows.
- [ ] **Step 2: Verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Gates + commit**

```bash
git add frontend/back-office && git commit -m "M6: reports and audit screens, client-side CSV"
```

---

### Task 12: Docs, e2e admin day, suite proof

**Files:**
- Modify: `docs/03-api.md` (the `/admin/*` surface — auth, CRUD conventions incl. archive-never-delete, reports with the ledger-vs-lines basis note, audit viewer, open-shifts endpoint, `invalid_credentials`/`self_lockout` codes, Z `orders_split` field), `docs/05-rbac.md` (admin-only back office + the bookkeeper deferral rationale), `docs/06-roadmap.md` (M6 **Status: complete** block in the M3–M5 voice: what building it taught), `docs/01-architecture.md` (two-frontend topology sentence + admin auth tier), `CLAUDE.md` (layout gains `frontend/back-office`, run instructions port 5175, status M6 complete, new gotchas if earned), `infra`/`README` only if run instructions changed
- Create: `scripts/e2e-admin-day.sh`
- Test: full suites, all three surfaces

**Interfaces:** none — this task proves and records.

- [ ] **Step 1: e2e script.** Mirror `scripts/e2e-lunch-service.sh` structure exactly: `set -euo pipefail`, `req()` helper, `fail()`, asserts with real values. Credentials via env: `POS_ADMIN_EMAIL="${POS_ADMIN_EMAIL:?...}"`, `POS_ADMIN_PASSWORD`, `POS_DEVICE_TOKEN` (for the register leg). The story:
  1. Admin login → token.
  2. Build from nothing: category "Drinks" → tax rate 10% → product "Flat White" (food kind, category) → variant (SKU `FW-1`, 450¢, tax rate, untracked) → modifier group "Milk" (min 1 max 1) + modifiers Oat(+60)/Whole(0) → attach group to product.
  3. Hire "Eve" with PIN from env (`POS_E2E_PIN:?`), cashier role at DT; assert she appears in `GET /admin/users` with the role.
  4. Set Till 1 `mode=food`; reissue its token → capture the NEW device token; assert the OLD token now 401s on `/catalog`.
  5. Register leg (new token): Eve PIN-login, open shift float 5000, open tab, add Flat White + Oat (assert 510¢ total server-side), pay cash 510, receipt shows the modifier.
  6. Admin leg: reprice variant to 500¢; re-fetch the PAID order's receipt — still 510¢ line (snapshot proof); sales report `day` for today: gross ≥ 510 with the exact seeded-day arithmetic asserted (fresh seed makes it exactly 510); `category` report shows Drinks with 510; `user` report attributes 510 to Eve.
  7. Audit viewer: filter `action=admin.product.create` finds the product; `entity_type=ProductVariant` + the variant id finds the reprice with from/to cents; `admin.register.token_reissue` present.
  8. Close the shift (Eve counts 5510, variance 0). Print the summary table.
- [ ] **Step 2: Run it green** against fresh `php artisan migrate:fresh --seed` + live API (`php artisan serve`), with env exported from the seeder's printed table. Paste the summary into the report.
- [ ] **Step 3: Full suites** — `cd backend && DB_PORT=5433 ./vendor/bin/pest`; `cd frontend/web && npm test && npm run typecheck && npm run build`; `cd frontend/back-office && npm test && npm run typecheck && npm run build`.
- [ ] **Step 4: Docs** — surgical edits per the file list; the roadmap's M6 status block records at minimum: the M2 schema (email/password columns, enroll route, permission names) finally earning its keep; archive-never-delete as the CRUD spine; the ledger-vs-lines report basis distinction; admin-only auth with the bookkeeper deferral; the reissue-token kill switch.
- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "M6: docs current + e2e admin day green"
```

---

## Plan self-review (performed at write time)

- **Spec coverage:** auth (T1), catalog CRUD + attach (T2, T3), users + roles + self-lockout (T4), locations/registers/mode/reissue + open-shifts fold-in (T5), reports + low threshold (T6), audit viewer + Z split annotation + cleanups fold-ins (T7), app scaffold (T8), catalog screens (T9), users/places screens + register transfer-picker fix (T10), reports/audit screens + CSV (T11), docs + e2e + suites (T12). Spec's deferred table appears in no task — correct.
- **Placeholder scan:** the api-client comment block in T8 enumerates method names later tasks implement — those are interface declarations consumed by T9–T11, each of which carries its own implementation step; no TBDs remain.
- **Type consistency:** `EnrolledRegister{register, plainTextToken}` reused by T5's reissue matches the M2 class; `model_has_roles` writes in T4 flagged to verify real column names against the published migration before use; T6's `basis` field consumed by T11's label; T5's open-shifts row shape consumed verbatim by T10's register-app client method.
- **Known judgment calls encoded:** login refusals identical across all four causes (enumeration); PATCH `changes`-array semantics for partial updates; category report is line-basis and labeled as such; reissue kills the old token inside the transaction; no DELETE routes exist anywhere.

