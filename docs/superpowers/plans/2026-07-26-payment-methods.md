# Payment Methods Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every location an admin-managed tender taxonomy — payment method groups (group code + one driver) holding payment methods (method code) — so the till offers real tenders, every payment and refund records which one it was taken on, and reports break down by method and group.

**Architecture:** Two new per-location tables (`payment_method_groups`, `payment_methods`); the group carries the driver, so the existing `PaymentDriver` seam is untouched. A `PaymentMethodResolver` turns `(location, code)` into a driver at write time; `payments`/`refunds` gain a method FK plus snapshot code/name and keep their derived `driver` column so all drawer-variance math is unchanged. Back office gets a new permission-gated section; the till reads its methods from the existing `GET /catalog` payload.

**Tech Stack:** Laravel 13.20 / PHP 8.5 / PostgreSQL 18, Pest. Next.js 16 + React 19 + TypeScript 7 + React Query, Vitest, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-07-26-payment-methods-design.md`

## Global Constraints

- **Money is integer cents** (`bigint` / PHP `int`), wire suffix `_cents`. Never a float, any layer.
- **One action = one route = one controller = one Action class.** Actions take an Input DTO, return a domain object, never touch HTTP. Actions are `final`. `declare(strict_types=1);` in every PHP file.
- **Never call `env()` outside `config/`.** `tests/Arch/` enforces this and the rules above.
- **Tests run against real Postgres, never SQLite.**
- **`jsonb` reorders keys** — compare with `toEqual`, never `toBe`.
- **Eloquent `create()` never hydrates DB column defaults** — set them explicitly or `->refresh()`.
- **A constraint violation aborts the whole Postgres transaction** — under `RefreshDatabase`, nothing after a provoked violation runs. One violation per test.
- **Admin `FormRequest::authorize()` uses `AuthorizesBackOffice::allowsBackOffice()`, never bare `can()`.** Register-tier requests use `$this->user()->can(...)`.
- **Never read role assignments through spatie's `roles()`/`permissions()` relations** — query `model_has_roles` / `model_has_permissions` directly.
- **Archive, never delete.** No `DELETE` route anywhere under `/admin/*`.
- **Shared UI is byte-identical** between `frontend/web` and `frontend/back-office`: `src/styles/carbon.css`, `src/lib/utils.ts`, all of `src/components/ui/*`, `StatusPill`/`EmptyState`/`ConfirmDialog`. Edit both copies or neither. This plan adds no shared components.
- **`docker compose exec` against a bind-mounted service needs `--user pos` (api) or `--user node` (frontends)**, or it leaves root-owned files. The Makefile targets already do this.
- **Codes are `UPPERCASE`, immutable after create.** Names are editable display copy.
- Commands: `make test-backend`, `make test-web`, `make test-bo`, `make e2e`. Native backend: `cd backend && ./vendor/bin/pest`.

---

## File Structure

**Backend — created**

| File | Responsibility |
| --- | --- |
| `database/migrations/2026_07_26_000100_create_payment_method_tables.php` | The two tables, indexes, checks; provisions defaults into existing locations |
| `database/migrations/2026_07_26_000200_add_payment_method_to_ledgers.php` | Three columns on `payments` + `refunds`, backfill, `set not null` |
| `app/Models/PaymentMethodGroup.php`, `app/Models/PaymentMethod.php` | Eloquent models |
| `app/Domain/Payments/PaymentMethodProvisioner.php` | One location's default `CASH`/`CARD` set, idempotent |
| `app/Domain/Payments/PaymentMethodResolver.php`, `ResolvedPaymentMethod.php` | `(locationId, code)` → id/code/name/groupCode/driver |
| `app/Exceptions/Domain/PaymentMethodUnknown.php`, `PaymentMethodInactive.php`, `RefundMethodNotRefundable.php` | 422s |
| `app/Actions/Admin/PaymentMethods/*` | 6 actions + 4 Input DTOs (list/create/update × group/method) |
| `app/Http/Requests/Admin/PaymentMethods/*` | 6 FormRequests |
| `app/Http/Controllers/Admin/PaymentMethods/*` | 6 controllers |
| `app/Http/Resources/Admin/AdminPaymentMethodGroupResource.php`, `AdminPaymentMethodResource.php` | Serialization |
| `database/factories/PaymentMethodGroupFactory.php`, `PaymentMethodFactory.php` | Test fixtures |

**Backend — modified**

`app/Domain/Rbac/Permissions.php`, `app/Domain/Rbac/AdminAccess.php`, `app/Actions/Admin/Locations/CreateLocation.php`, `app/Actions/Payments/TakePayment.php` + `TakePaymentInput.php`, `app/Http/Requests/Payments/TakePaymentRequest.php`, `app/Http/Resources/TakePaymentResource.php`, `app/Actions/Refunds/RefundOrder.php` + `RefundOrderInput.php`, `app/Http/Requests/Refunds/RefundOrderRequest.php`, `app/Http/Resources/ReceiptResource.php`, `app/Actions/Catalog/GetCatalog.php` + `CatalogSnapshot.php`, `app/Http/Resources/CatalogResource.php`, `app/Actions/Reports/GetZReport.php` + `ZReport.php`, `app/Http/Resources/ZReportResource.php`, `app/Actions/Admin/Reports/SalesReport.php`, `app/Http/Requests/Admin/Reports/SalesReportRequest.php`, `routes/api.php`, `tests/Pest.php`, the three catalog seeders.

**Frontend**

| File | Responsibility |
| --- | --- |
| `back-office/src/admin/navigation.ts` | One `SECTION_DEFS` entry |
| `back-office/src/admin/payments/PaymentMethodsSection.tsx` | Groups + nested methods, create/edit/archive |
| `back-office/src/admin/payments/GroupEditor.tsx`, `MethodEditor.tsx` | The two dialogs |
| `back-office/src/lib/api.ts` | Types + `paymentMethodGroups` / `paymentMethods` clients |
| `back-office/src/admin/Shell.tsx` | Nav item + render case |
| `web/src/lib/api.ts` | `CatalogPaymentMethod` type, `takePayment`/`refund` on method codes |
| `web/src/register/SaleScreen.tsx` | Tender buttons from the catalog |

---

## Task 1: Schema, models, and the default-set provisioner

**Files:**
- Create: `backend/database/migrations/2026_07_26_000100_create_payment_method_tables.php`
- Create: `backend/app/Models/PaymentMethodGroup.php`, `backend/app/Models/PaymentMethod.php`
- Create: `backend/app/Domain/Payments/PaymentMethodProvisioner.php`
- Create: `backend/database/factories/PaymentMethodGroupFactory.php`, `backend/database/factories/PaymentMethodFactory.php`
- Modify: `backend/app/Actions/Admin/Locations/CreateLocation.php`
- Modify: `backend/tests/Pest.php` (`provisionedLocation()`)
- Test: `backend/tests/Feature/Payments/PaymentMethodProvisionerTest.php`, `backend/tests/Feature/Schema/PaymentMethodConstraintsTest.php`

**Interfaces:**
- Consumes: `provisionedLocation()`, `Location` model (existing).
- Produces: `PaymentMethodGroup` / `PaymentMethod` models; `PaymentMethodProvisioner::provisionForLocation(string $locationId): void`; the constants `PaymentMethodProvisioner::DEFAULTS`; factories `PaymentMethodGroup::factory()` / `PaymentMethod::factory()`. Every later task depends on `payment_method_groups` and `payment_methods` existing with these exact column names.

- [ ] **Step 1: Write the failing constraint test**

Create `backend/tests/Feature/Schema/PaymentMethodConstraintsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use Illuminate\Database\QueryException;

it('allows the same code at two different locations', function (): void {
    $a = Location::factory()->create(['code' => 'AAA']);
    $b = Location::factory()->create(['code' => 'BBB']);

    foreach ([$a, $b] as $location) {
        $group = PaymentMethodGroup::factory()->create([
            'location_id' => $location->id, 'code' => 'CASH', 'driver' => 'cash',
        ]);
        PaymentMethod::factory()->create([
            'location_id' => $location->id, 'group_id' => $group->id, 'code' => 'CASH',
        ]);
    }

    expect(PaymentMethod::query()->where('code', 'CASH')->count())->toBe(2);
});

it('refuses two groups sharing a code at one location', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    PaymentMethodGroup::factory()->create(['location_id' => $location->id, 'code' => 'CASH']);

    expect(fn () => PaymentMethodGroup::factory()->create([
        'location_id' => $location->id, 'code' => 'CASH',
    ]))->toThrow(QueryException::class);
});

it('refuses two methods sharing a code at one location', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    $group = PaymentMethodGroup::factory()->create(['location_id' => $location->id, 'code' => 'CASH']);
    PaymentMethod::factory()->create([
        'location_id' => $location->id, 'group_id' => $group->id, 'code' => 'CASH',
    ]);

    expect(fn () => PaymentMethod::factory()->create([
        'location_id' => $location->id, 'group_id' => $group->id, 'code' => 'CASH',
    ]))->toThrow(QueryException::class);
});

it('refuses a method pointing at another location\'s group', function (): void {
    $a = Location::factory()->create(['code' => 'AAA']);
    $b = Location::factory()->create(['code' => 'BBB']);
    $groupAtA = PaymentMethodGroup::factory()->create(['location_id' => $a->id, 'code' => 'CASH']);

    // The composite FK (group_id, location_id) is what rejects this, not a validator.
    expect(fn () => PaymentMethod::factory()->create([
        'location_id' => $b->id, 'group_id' => $groupAtA->id, 'code' => 'CASH',
    ]))->toThrow(QueryException::class);
});

it('refuses a driver outside the registry', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);

    expect(fn () => PaymentMethodGroup::factory()->create([
        'location_id' => $location->id, 'code' => 'CRYPTO', 'driver' => 'bitcoin',
    ]))->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/PaymentMethodConstraintsTest.php`
Expected: FAIL — `Class "App\Models\PaymentMethodGroup" not found`.

- [ ] **Step 3: Write the migration**

Create `backend/database/migrations/2026_07_26_000100_create_payment_method_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location tender taxonomy. The GROUP carries the driver — it is the behavioural
 * bucket — and its methods are admin-named variants that behave identically.
 * See docs/02-data-model.md (payment methods).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('location_id')->constrained('locations');
            $table->text('code');                        // 'CASH','CARD','EWALLET' — immutable
            $table->text('name');                        // display copy — editable
            $table->text('driver');                      // the code seam: cash | external_card
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('create unique index payment_method_groups_code
            on payment_method_groups (location_id, code)');

        // Exists only so payment_methods can carry a composite FK onto (id, location_id).
        DB::statement('create unique index payment_method_groups_id_location
            on payment_method_groups (id, location_id)');

        DB::statement("alter table payment_method_groups add constraint payment_method_groups_driver
            check (driver in ('cash','external_card'))");

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('location_id')->constrained('locations');
            $table->uuid('group_id');
            $table->text('code');                        // 'CASH','VISA','GCASH' — immutable
            $table->text('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('group_id');
        });

        // "A method's group is at the method's location", in the schema rather than in
        // trust — location_id is duplicated onto this table because the uniqueness rule
        // is per-location, and this is what keeps the duplicate honest.
        DB::statement('alter table payment_methods
            add constraint payment_methods_group_same_location
            foreign key (group_id, location_id)
            references payment_method_groups (id, location_id)');

        DB::statement('create unique index payment_methods_code
            on payment_methods (location_id, code)');

        // Every existing location gets the default set. Inlined as SQL on purpose: a
        // migration must not depend on a domain class that may change under it.
        // Mirrors App\Domain\Payments\PaymentMethodProvisioner::DEFAULTS.
        $defaults = [
            ['CASH', 'Cash', 'cash', 0, 'CASH', 'Cash'],
            ['CARD', 'Cards', 'external_card', 1, 'CARD', 'Card'],
        ];

        foreach (DB::table('locations')->pluck('id') as $locationId) {
            foreach ($defaults as [$groupCode, $groupName, $driver, $sort, $methodCode, $methodName]) {
                $groupId = DB::table('payment_method_groups')->insertGetId([
                    'location_id' => $locationId,
                    'code' => $groupCode,
                    'name' => $groupName,
                    'driver' => $driver,
                    'sort_order' => $sort,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'id');

                DB::table('payment_methods')->insert([
                    'location_id' => $locationId,
                    'group_id' => $groupId,
                    'code' => $methodCode,
                    'name' => $methodName,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('payment_method_groups');
    }
};
```

- [ ] **Step 4: Write the two models**

Create `backend/app/Models/PaymentMethodGroup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The behavioural bucket: one driver, per location. `code` and `driver` are immutable
 * after create — changing either would silently change how every method under it
 * behaves and retroactively re-bucket history. See docs/02-data-model.md.
 */
class PaymentMethodGroup extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['location_id', 'code', 'name', 'driver', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<PaymentMethod, $this> */
    public function methods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'group_id');
    }
}
```

Create `backend/app/Models/PaymentMethod.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin-named tender at one location. `code` and `group_id` are immutable after
 * create: the group carries the driver, so moving a method between groups would change
 * its behaviour, and the code is a wire identifier and a report key.
 * See docs/02-data-model.md.
 */
class PaymentMethod extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['location_id', 'group_id', 'code', 'name', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<PaymentMethodGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PaymentMethodGroup::class, 'group_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
```

- [ ] **Step 5: Write the two factories**

Create `backend/database/factories/PaymentMethodGroupFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\PaymentMethodGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethodGroup> */
class PaymentMethodGroupFactory extends Factory
{
    protected $model = PaymentMethodGroup::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'code' => 'CASH',
            'name' => 'Cash',
            'driver' => 'cash',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
```

Create `backend/database/factories/PaymentMethodFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethod> */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        // group_id and location_id must agree (composite FK) — callers that care pass
        // both explicitly; this default derives the location from the group it makes.
        $group = PaymentMethodGroup::factory()->create();

        return [
            'location_id' => $group->location_id,
            'group_id' => $group->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 6: Run the constraint test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/PaymentMethodConstraintsTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 7: Write the failing provisioner test**

Create `backend/tests/Feature/Payments/PaymentMethodProvisionerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Payments\PaymentMethodProvisioner;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use App\Models\User;

it('writes the default cash and card set', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);

    app(PaymentMethodProvisioner::class)->provisionForLocation($location->id);

    $groups = PaymentMethodGroup::query()->where('location_id', $location->id)
        ->orderBy('sort_order')->get();
    expect($groups->pluck('code')->all())->toBe(['CASH', 'CARD']);
    expect($groups->pluck('driver')->all())->toBe(['cash', 'external_card']);

    $methods = PaymentMethod::query()->where('location_id', $location->id)
        ->orderBy('code')->get();
    expect($methods->pluck('code')->all())->toBe(['CARD', 'CASH']);
});

it('is idempotent — a second call adds nothing', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    $provisioner = app(PaymentMethodProvisioner::class);

    $provisioner->provisionForLocation($location->id);
    $provisioner->provisionForLocation($location->id);

    expect(PaymentMethodGroup::query()->where('location_id', $location->id)->count())->toBe(2);
    expect(PaymentMethod::query()->where('location_id', $location->id)->count())->toBe(2);
});

it('leaves an admin-renamed default alone on a re-run', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    $provisioner = app(PaymentMethodProvisioner::class);
    $provisioner->provisionForLocation($location->id);

    PaymentMethodGroup::query()->where('location_id', $location->id)
        ->where('code', 'CASH')->update(['name' => 'Cash (peso)']);

    $provisioner->provisionForLocation($location->id);

    expect(PaymentMethodGroup::query()->where('location_id', $location->id)
        ->where('code', 'CASH')->value('name'))->toBe('Cash (peso)');
});

it('provisions a location created through the back office', function (): void {
    $admin = User::factory()->create([
        'email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true,
    ]);
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    $created = $this->postJson('/api/v1/admin/locations', [
        'name' => 'Makati', 'code' => 'MKT', 'timezone' => 'Asia/Manila',
    ], $headers)->assertCreated();

    // RBAC v2 fixed exactly this bug for roles; a location with no tenders is the same
    // bug with a different noun — every payment at it would 422.
    expect(PaymentMethod::query()
        ->where('location_id', $created->json('data.location.id'))->count())->toBe(2);
});
```

- [ ] **Step 8: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Payments/PaymentMethodProvisionerTest.php`
Expected: FAIL — `Class "App\Domain\Payments\PaymentMethodProvisioner" not found`.

- [ ] **Step 9: Write the provisioner**

Create `backend/app/Domain/Payments/PaymentMethodProvisioner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use Illuminate\Support\Facades\DB;

/**
 * One location's default tender set. The counterpart to RoleProvisioner, and for the
 * same reason: RBAC v2 fixed a confirmed bug where a location created in the back office
 * got no roles and was unusable. A location with no payment methods is that bug with a
 * different noun — every tender at it would 422.
 *
 * Idempotent by code, so re-running it never duplicates a row and never overwrites a
 * name an admin has since edited.
 */
final class PaymentMethodProvisioner
{
    /** @var list<array{code: string, name: string, driver: string, method: string, methodName: string}> */
    public const array DEFAULTS = [
        ['code' => 'CASH', 'name' => 'Cash', 'driver' => 'cash', 'method' => 'CASH', 'methodName' => 'Cash'],
        ['code' => 'CARD', 'name' => 'Cards', 'driver' => 'external_card', 'method' => 'CARD', 'methodName' => 'Card'],
    ];

    public function provisionForLocation(string $locationId): void
    {
        DB::transaction(function () use ($locationId): void {
            foreach (self::DEFAULTS as $sort => $default) {
                $group = PaymentMethodGroup::query()
                    ->where('location_id', $locationId)
                    ->where('code', $default['code'])
                    ->first();

                $group ??= PaymentMethodGroup::create([
                    'location_id' => $locationId,
                    'code' => $default['code'],
                    'name' => $default['name'],
                    'driver' => $default['driver'],
                    'sort_order' => $sort,
                    'is_active' => true,
                ]);

                $exists = PaymentMethod::query()
                    ->where('location_id', $locationId)
                    ->where('code', $default['method'])
                    ->exists();

                if (! $exists) {
                    PaymentMethod::create([
                        'location_id' => $locationId,
                        'group_id' => $group->id,
                        'code' => $default['method'],
                        'name' => $default['methodName'],
                        'sort_order' => 0,
                        'is_active' => true,
                    ]);
                }
            }
        });
    }
}
```

- [ ] **Step 10: Call it from `CreateLocation`**

`backend/app/Actions/Admin/Locations/CreateLocation.php` already provisions roles inside its `DB::transaction`. Append a third constructor dependency (do not reorder or rename the two that exist):

```php
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoleProvisioner $provisioner,
        private readonly PaymentMethodProvisioner $paymentMethods,
    ) {}
```

and add one line immediately after the existing `$this->provisioner->provisionForLocation($location);`:

```php
            $this->provisioner->provisionForLocation($location);

            // A location with no tenders can take no payment — the same class of bug the
            // roles call above fixes. See PaymentMethodProvisioner.
            $this->paymentMethods->provisionForLocation($location->id);
```

Add `use App\Domain\Payments\PaymentMethodProvisioner;` to the import block (alphabetically it goes above `use App\Domain\Rbac\RoleProvisioner;`).

- [ ] **Step 11: Provision in the test helper**

In `backend/tests/Pest.php`, `provisionedLocation()` currently provisions roles only. Add the tender set so every test that takes a payment has a method to name:

```php
/** A location with the role/permission catalog AND its default tenders provisioned. */
function provisionedLocation(array $attrs = []): Location
{
    $location = Location::factory()->create($attrs);
    $provisioner = app(RoleProvisioner::class);
    $provisioner->provisionGlobal();
    $provisioner->provisionForLocation($location);
    // Task 4 makes payments.payment_method_id NOT NULL — every location a test takes a
    // payment at needs its default CASH/CARD set, same as it needs roles.
    app(PaymentMethodProvisioner::class)->provisionForLocation($location->id);

    return $location;
}
```

Add `use App\Domain\Payments\PaymentMethodProvisioner;` to the `use` block at the top of `tests/Pest.php`.

- [ ] **Step 12: Run the provisioner test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Payments/PaymentMethodProvisionerTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 13: Run the full backend suite**

Run: `make test-backend`
Expected: PASS — every pre-existing test still green (nothing reads the new tables yet), plus 9 new.

- [ ] **Step 14: Commit**

```bash
git add backend/database/migrations/2026_07_26_000100_create_payment_method_tables.php \
        backend/app/Models/PaymentMethodGroup.php backend/app/Models/PaymentMethod.php \
        backend/app/Domain/Payments/PaymentMethodProvisioner.php \
        backend/database/factories/PaymentMethodGroupFactory.php \
        backend/database/factories/PaymentMethodFactory.php \
        backend/app/Actions/Admin/Locations/CreateLocation.php \
        backend/tests/Pest.php \
        backend/tests/Feature/Payments/PaymentMethodProvisionerTest.php \
        backend/tests/Feature/Schema/PaymentMethodConstraintsTest.php
git commit -m "feat(payments): per-location payment method groups and methods"
```

---

## Task 2: The `payment_method.manage` permission

**Files:**
- Modify: `backend/app/Domain/Rbac/Permissions.php`
- Modify: `backend/app/Domain/Rbac/AdminAccess.php:18-23`
- Test: `backend/tests/Feature/Rbac/PaymentMethodPermissionTest.php`

**Interfaces:**
- Produces: `Permissions::PAYMENT_METHOD_MANAGE` (the string `'payment_method.manage'`), present in `Permissions::all()`, in `Permissions::grouped()['Administration']`, and in `AdminAccess::SECTIONS`. Tasks 9, 10 and 13 depend on all four.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Rbac/PaymentMethodPermissionTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\Permissions;

it('is in the permission catalog and the admin section list', function (): void {
    expect(Permissions::all())->toContain(Permissions::PAYMENT_METHOD_MANAGE);
    expect(AdminAccess::SECTIONS)->toContain(Permissions::PAYMENT_METHOD_MANAGE);
    expect(Permissions::grouped()['Administration'])->toContain(Permissions::PAYMENT_METHOD_MANAGE);
});

it('is granted by no default role and is not a money-leaves permission', function (): void {
    // Only admins configure tenders, and admins bypass the gate entirely (Gate::before)
    // — same shape as catalog.manage and day.close. Naming a tender does not move money
    // out of a till; taking one is still gated by payment.take.
    expect(Permissions::cashier())->not->toContain(Permissions::PAYMENT_METHOD_MANAGE);
    expect(Permissions::supervisor())->not->toContain(Permissions::PAYMENT_METHOD_MANAGE);
    expect(Permissions::moneyLeaves())->not->toContain(Permissions::PAYMENT_METHOD_MANAGE);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Rbac/PaymentMethodPermissionTest.php`
Expected: FAIL — `Undefined constant App\Domain\Rbac\Permissions::PAYMENT_METHOD_MANAGE`.

- [ ] **Step 3: Add the constant**

In `backend/app/Domain/Rbac/Permissions.php`, in the "Catalog and admin" block, after `ROLE_MANAGE`:

```php
    /**
     * The per-location tender taxonomy (payment method groups and methods). Admin-tier:
     * granted by no default role, doubles as the back-office section. Deliberately NOT
     * in moneyLeaves() — naming a tender moves no money; taking one is payment.take.
     */
    public const string PAYMENT_METHOD_MANAGE = 'payment_method.manage';
```

Add `self::PAYMENT_METHOD_MANAGE,` to `all()` immediately after `self::ROLE_MANAGE,`. Add it to the `'Administration'` array in `grouped()` at the end of that array. Do **not** add it to `cashier()`, `supervisor()`, or `moneyLeaves()`.

- [ ] **Step 4: Add it to the section list**

In `backend/app/Domain/Rbac/AdminAccess.php`, append to the `SECTIONS` array (order in this array is the canonical section order the login response returns, so put it after `SETTINGS_MANAGE` and before `ROLE_MANAGE` to match the sidebar's Operations grouping):

```php
    public const array SECTIONS = [
        Permissions::CATALOG_MANAGE, Permissions::USER_MANAGE, Permissions::LOCATION_MANAGE,
        Permissions::REGISTER_ENROLL, Permissions::AUDIT_VIEW, Permissions::REPORT_SALES_VIEW,
        Permissions::REPORT_STOCK_VIEW, Permissions::PAYMENT_METHOD_MANAGE,
        Permissions::SETTINGS_MANAGE, Permissions::ROLE_MANAGE, Permissions::DAY_CLOSE,
    ];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Rbac/PaymentMethodPermissionTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Run the RBAC and admin suites**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Rbac tests/Feature/Admin`
Expected: PASS. If a test asserts an exact permission **count** or an exact `sections[]` array, update its expected value — the catalog legitimately grew by one.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Domain/Rbac/Permissions.php backend/app/Domain/Rbac/AdminAccess.php \
        backend/tests/Feature/Rbac/PaymentMethodPermissionTest.php
git commit -m "feat(rbac): payment_method.manage permission and back-office section"
```

---

## Task 3: `PaymentMethodResolver` and its two exceptions

**Files:**
- Create: `backend/app/Domain/Payments/ResolvedPaymentMethod.php`, `backend/app/Domain/Payments/PaymentMethodResolver.php`
- Create: `backend/app/Exceptions/Domain/PaymentMethodUnknown.php`, `backend/app/Exceptions/Domain/PaymentMethodInactive.php`
- Test: `backend/tests/Feature/Payments/PaymentMethodResolverTest.php`

**Interfaces:**
- Consumes: `payment_method_groups` / `payment_methods` tables (Task 1).
- Produces: `PaymentMethodResolver::resolve(string $locationId, string $code): ResolvedPaymentMethod`; `ResolvedPaymentMethod` with public readonly `string $id, $code, $name, $groupCode, $driver`. Tasks 4, 5 and 11 consume both.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Payments/PaymentMethodResolverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Payments\PaymentMethodResolver;
use App\Exceptions\Domain\PaymentMethodInactive;
use App\Exceptions\Domain\PaymentMethodUnknown;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;

beforeEach(function (): void {
    $this->location = Location::factory()->create(['code' => 'AAA']);
    $this->group = PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card',
    ]);
    $this->method = PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $this->group->id,
        'code' => 'GCASH', 'name' => 'GCash',
    ]);
});

it('resolves a method to its group\'s driver', function (): void {
    $resolved = app(PaymentMethodResolver::class)->resolve($this->location->id, 'GCASH');

    expect($resolved->id)->toBe($this->method->id);
    expect($resolved->code)->toBe('GCASH');
    expect($resolved->name)->toBe('GCash');
    expect($resolved->groupCode)->toBe('EWALLET');
    // The group carries the driver — this is the whole point of the indirection.
    expect($resolved->driver)->toBe('external_card');
});

it('refuses a code that does not exist at the location', function (): void {
    expect(fn () => app(PaymentMethodResolver::class)->resolve($this->location->id, 'MAYA'))
        ->toThrow(PaymentMethodUnknown::class);
});

it('refuses another location\'s code', function (): void {
    $other = Location::factory()->create(['code' => 'BBB']);

    // Not a bypass and not a 500: the code is simply unknown *here*.
    expect(fn () => app(PaymentMethodResolver::class)->resolve($other->id, 'GCASH'))
        ->toThrow(PaymentMethodUnknown::class);
});

it('refuses an archived method', function (): void {
    $this->method->update(['is_active' => false]);

    expect(fn () => app(PaymentMethodResolver::class)->resolve($this->location->id, 'GCASH'))
        ->toThrow(PaymentMethodInactive::class);
});

it('refuses an active method inside an archived group', function (): void {
    // One switch turns off "we take e-wallets" without touching the methods under it.
    $this->group->update(['is_active' => false]);

    expect(fn () => app(PaymentMethodResolver::class)->resolve($this->location->id, 'GCASH'))
        ->toThrow(PaymentMethodInactive::class);
});

it('reports 422 with distinct error codes', function (): void {
    expect((new PaymentMethodUnknown('loc', 'MAYA'))->errorCode())->toBe('payment_method_unknown');
    expect((new PaymentMethodUnknown('loc', 'MAYA'))->httpStatus())->toBe(422);
    expect((new PaymentMethodInactive('loc', 'GCASH'))->errorCode())->toBe('payment_method_inactive');
    expect((new PaymentMethodInactive('loc', 'GCASH'))->httpStatus())->toBe(422);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Payments/PaymentMethodResolverTest.php`
Expected: FAIL — `Class "App\Domain\Payments\PaymentMethodResolver" not found`.

- [ ] **Step 3: Write the two exceptions**

Create `backend/app/Exceptions/Domain/PaymentMethodUnknown.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * No payment method with this code exists at this location.
 *
 * Distinct from PaymentMethodInactive on purpose: "no such tender here" and "you turned
 * this off yesterday" are different problems for whoever reads the error. Another
 * location's code lands here too — it is unknown *here*, which is the honest answer.
 */
final class PaymentMethodUnknown extends DomainException
{
    public function __construct(
        private readonly string $locationId,
        private readonly string $code,
    ) {
        parent::__construct("No payment method '{$code}' is offered at this location.");
    }

    public function errorCode(): string
    {
        return 'payment_method_unknown';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['location_id' => $this->locationId, 'payment_method_code' => $this->code];
    }
}
```

Create `backend/app/Exceptions/Domain/PaymentMethodInactive.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The method exists at this location but is archived — either itself, or via its group.
 * Archiving a group hides every method under it without touching their rows.
 */
final class PaymentMethodInactive extends DomainException
{
    public function __construct(
        private readonly string $locationId,
        private readonly string $code,
    ) {
        parent::__construct("Payment method '{$code}' is archived at this location.");
    }

    public function errorCode(): string
    {
        return 'payment_method_inactive';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['location_id' => $this->locationId, 'payment_method_code' => $this->code];
    }
}
```

- [ ] **Step 4: Write the resolved value object**

Create `backend/app/Domain/Payments/ResolvedPaymentMethod.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * A method resolved against one location: what to snapshot onto the ledger row, and
 * which driver to hand to DriverRegistry.
 */
final readonly class ResolvedPaymentMethod
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $groupCode,
        public string $driver,
    ) {}
}
```

- [ ] **Step 5: Write the resolver**

Create `backend/app/Domain/Payments/PaymentMethodResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Exceptions\Domain\PaymentMethodInactive;
use App\Exceptions\Domain\PaymentMethodUnknown;
use Illuminate\Support\Facades\DB;

/**
 * Turns the code a till sends into the driver the payment path already understands.
 * This is the only place the group→driver indirection is read, which is what keeps
 * PaymentDriver, DriverRegistry and Capabilities untouched by the taxonomy above them.
 *
 * Read-only and cheap: one indexed join, called inside the caller's transaction.
 */
final class PaymentMethodResolver
{
    public function resolve(string $locationId, string $code): ResolvedPaymentMethod
    {
        $row = DB::table('payment_methods as pm')
            ->join('payment_method_groups as g', 'g.id', '=', 'pm.group_id')
            ->where('pm.location_id', $locationId)
            ->where('pm.code', $code)
            ->first([
                'pm.id', 'pm.code', 'pm.name', 'pm.is_active as method_active',
                'g.code as group_code', 'g.driver', 'g.is_active as group_active',
            ]);

        if ($row === null) {
            throw new PaymentMethodUnknown($locationId, $code);
        }

        // An archived GROUP hides its methods without touching their rows — one switch
        // for "we stopped taking cards" instead of five.
        if (! $row->method_active || ! $row->group_active) {
            throw new PaymentMethodInactive($locationId, $code);
        }

        return new ResolvedPaymentMethod(
            id: (string) $row->id,
            code: (string) $row->code,
            name: (string) $row->name,
            groupCode: (string) $row->group_code,
            driver: (string) $row->driver,
        );
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Payments/PaymentMethodResolverTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 7: Run the arch suite**

Run: `cd backend && ./vendor/bin/pest tests/Arch`
Expected: PASS — the new classes are `final`, strict-typed, and touch no HTTP.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Domain/Payments/ResolvedPaymentMethod.php \
        backend/app/Domain/Payments/PaymentMethodResolver.php \
        backend/app/Exceptions/Domain/PaymentMethodUnknown.php \
        backend/app/Exceptions/Domain/PaymentMethodInactive.php \
        backend/tests/Feature/Payments/PaymentMethodResolverTest.php
git commit -m "feat(payments): resolve a per-location method code to its group's driver"
```

---

## Task 4: Ledger columns and `TakePayment` on method codes

This is the breaking task: `driver` leaves the payment request body. It touches many
existing tests, so it adds a `tenderColumns()` Pest helper first to keep that churn small.

**Files:**
- Create: `backend/database/migrations/2026_07_26_000200_add_payment_method_to_ledgers.php`
- Modify: `backend/app/Actions/Payments/TakePaymentInput.php`, `backend/app/Actions/Payments/TakePayment.php`
- Modify: `backend/app/Http/Requests/Payments/TakePaymentRequest.php`
- Modify: `backend/app/Http/Resources/TakePaymentResource.php`
- Modify: `backend/app/Models/Payment.php` (`$fillable`), `backend/app/Models/Refund.php` (`$fillable`)
- Modify: `backend/tests/Pest.php` (add `tenderColumns()`)
- Modify (test churn): `tests/Feature/Payments/TakePaymentTest.php`, `ExternalCardTest.php`, `VoidPaymentTest.php`, `tests/Feature/Orders/VoidOrderTest.php`, `ReopenOrderTest.php`, `AddLineModifiersTest.php`, `SettleZeroOrderTest.php`, `tests/Feature/Day/DayTotalsTest.php`, `tests/Feature/Admin/ReportsTest.php`, `tests/Feature/Reports/ZReportTest.php`, `tests/Feature/Shifts/CloseShiftTest.php`, `tests/Feature/Schema/ConstraintsTest.php`, `tests/Feature/Http/IdempotencyScopeTest.php`
- Test: `backend/tests/Feature/Payments/PaymentMethodTenderTest.php`

**Interfaces:**
- Consumes: `PaymentMethodResolver::resolve()` and `ResolvedPaymentMethod` (Task 3).
- Produces: `TakePaymentInput` with `public string $paymentMethodCode` **in place of** `public string $driver` (all other fields and their order unchanged); `payments`/`refunds` columns `payment_method_id`, `payment_method_code`, `payment_method_name` (all `not null`); Pest helper `tenderColumns(Location $location, string $code = 'CASH'): array`. Tasks 5–8, 11 and 15 depend on these names.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Payments/PaymentMethodTenderTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\PaymentMethodInactive;
use App\Exceptions\Domain\PaymentMethodUnknown;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->shift = Shift::factory()->create(['register_id' => $this->register->id]);
    $this->order = Order::factory()->create([
        'location_id' => $this->location->id,
        'register_id' => $this->register->id,
        'shift_id' => $this->shift->id,
        'opened_by' => $this->cashier->id,
        'total_cents' => 5000,
        'subtotal_cents' => 5000,
    ]);
});

function takeOn(object $t, string $code, ?int $tendered = null, ?string $reference = null): \App\Models\Payment
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $t->order->id,
        registerId: $t->register->id,
        paymentMethodCode: $code,
        amountCents: 5000,
        tenderedCents: $tendered,
        reference: $reference,
        expectedVersion: Order::findOrFail($t->order->id)->version,
        actorId: $t->cashier->id,
    ));
}

it('derives the driver from the method\'s group and snapshots code and name', function (): void {
    $payment = takeOn($this, 'CASH', tendered: 6000);

    expect($payment->driver)->toBe('cash');            // derived, never sent
    expect($payment->payment_method_code)->toBe('CASH');
    expect($payment->payment_method_name)->toBe('Cash');
    expect($payment->change_cents)->toBe(1000);        // cash driver still computes change
});

it('takes a tender on an admin-created e-wallet method', function (): void {
    // Same driver as CARD, its own group so the Z-report keeps the totals apart.
    $group = PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card', 'sort_order' => 2,
    ]);
    PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $group->id,
        'code' => 'GCASH', 'name' => 'GCash',
    ]);

    $payment = takeOn($this, 'GCASH', reference: '0917 555 0101');

    expect($payment->driver)->toBe('external_card');
    expect($payment->payment_method_code)->toBe('GCASH');
    expect($payment->payment_method_name)->toBe('GCash');
    expect($payment->reference)->toBe('0917 555 0101');
    expect($payment->change_cents)->toBeNull();
});

it('renaming a method never rewrites a past tender', function (): void {
    $payment = takeOn($this, 'CASH', tendered: 5000);

    PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CASH')->update(['name' => 'Cash (peso)']);

    // The snapshot is why a receipt from last year reprints identically.
    expect($payment->fresh()->payment_method_name)->toBe('Cash');
});

it('refuses a method the location does not offer', function (): void {
    expect(fn () => takeOn($this, 'MAYA', tendered: 5000))
        ->toThrow(PaymentMethodUnknown::class);
});

it('refuses an archived method', function (): void {
    PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CASH')->update(['is_active' => false]);

    expect(fn () => takeOn($this, 'CASH', tendered: 5000))
        ->toThrow(PaymentMethodInactive::class);
});

it('rejects the method code over HTTP with a 422 envelope', function (): void {
    $headers = staffHeaders($this->register, $this->cashier)
        + ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(), 'If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/payments", [
        'payment_method_code' => 'MAYA', 'amount_cents' => 5000, 'tendered_cents' => 5000,
    ], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'payment_method_unknown');
});

it('returns the method on the take-payment response', function (): void {
    $headers = staffHeaders($this->register, $this->cashier)
        + ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(), 'If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/payments", [
        'payment_method_code' => 'CASH', 'amount_cents' => 5000, 'tendered_cents' => 6000,
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.payment.payment_method_code', 'CASH')
        ->assertJsonPath('data.payment.payment_method_name', 'Cash')
        ->assertJsonPath('data.payment.driver', 'cash')
        ->assertJsonPath('data.payment.change_cents', 1000);
});

it('still refuses a driver-shaped body', function (): void {
    $headers = staffHeaders($this->register, $this->cashier)
        + ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(), 'If-Match' => '0'];

    // There is ONE way to name a tender, and `driver` is not it anymore. A body that names
    // no tender is MALFORMED, so it is 400 validation_failed — 422 is reserved for
    // structurally-fine requests that are semantically rejected (ApiErrorEnvelope).
    $this->postJson("/api/v1/orders/{$this->order->id}/payments", [
        'driver' => 'cash', 'amount_cents' => 5000, 'tendered_cents' => 5000,
    ], $headers)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Payments/PaymentMethodTenderTest.php`
Expected: FAIL — `Unknown named parameter $paymentMethodCode`.

- [ ] **Step 3: Write the ledger migration**

Create `backend/database/migrations/2026_07_26_000200_add_payment_method_to_ledgers.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every tender records the method it was taken on. Code and name are SNAPSHOTS — the
 * reason order lines snapshot price: renaming 'GCash' must not change what a receipt
 * printed last year says.
 *
 * `driver` stays on both tables as a DERIVED column, written from the resolved group.
 * That is what keeps ShiftTotals (which filters driver = 'cash' on payments AND refunds)
 * and the payments_change_balances check constraint untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'refunds'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('payment_method_id')->nullable();
                $blueprint->text('payment_method_code')->nullable();
                $blueprint->text('payment_method_name')->nullable();
            });
        }

        // Deterministic backfill: at this point in the migration sequence the only
        // methods that exist are the CASH/CARD defaults 000100 provisioned, so mapping
        // the old driver onto its default code is exact, not a best guess.
        DB::statement("
            update payments p
               set payment_method_id   = pm.id,
                   payment_method_code = pm.code,
                   payment_method_name = pm.name
              from orders o, payment_methods pm
             where p.order_id = o.id
               and pm.location_id = o.location_id
               and pm.code = case p.driver when 'cash' then 'CASH' else 'CARD' end
        ");

        DB::statement("
            update refunds r
               set payment_method_id   = pm.id,
                   payment_method_code = pm.code,
                   payment_method_name = pm.name
              from payment_methods pm
             where pm.location_id = r.location_id
               and pm.code = case r.driver when 'cash' then 'CASH' else 'CARD' end
        ");

        // `payments` only. TakePayment (this task) writes all three columns, so they can
        // be tightened now. `refunds` stays NULLABLE until Task 5 teaches RefundOrder to
        // write them — tightening a column before its writer exists would leave the refund
        // path returning a raw 23502 for the span of a commit, which is a dead financial
        // write path, not a task boundary. Task 5 owns the refunds tightening.
        DB::statement('alter table payments alter column payment_method_id set not null');
        DB::statement('alter table payments alter column payment_method_code set not null');
        DB::statement('alter table payments alter column payment_method_name set not null');

        foreach (['payments', 'refunds'] as $table) {
            DB::statement("alter table {$table}
                add constraint {$table}_payment_method_fk
                foreign key (payment_method_id) references payment_methods (id)");
            DB::statement("create index {$table}_payment_method on {$table} (payment_method_id)");
        }
    }

    public function down(): void
    {
        foreach (['payments', 'refunds'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['payment_method_id', 'payment_method_code', 'payment_method_name']);
            });
        }
    }
};
```

- [ ] **Step 4: Add the three columns to both models' `$fillable`**

In `backend/app/Models/Payment.php`, extend `$fillable`:

```php
    protected $fillable = [
        'order_id', 'shift_id', 'driver', 'status', 'amount_cents',
        'tendered_cents', 'change_cents', 'reference', 'driver_payload',
        'payment_method_id', 'payment_method_code', 'payment_method_name',
        'user_id', 'created_at', 'captured_at',
    ];
```

Do the same in `backend/app/Models/Refund.php` — append `'payment_method_id', 'payment_method_code', 'payment_method_name',` to its `$fillable` array, leaving the existing entries untouched.

- [ ] **Step 5: Rename the input field**

In `backend/app/Actions/Payments/TakePaymentInput.php`, replace `public string $driver,` with `public string $paymentMethodCode,` in the same position:

```php
final readonly class TakePaymentInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        // The per-location method code. The driver is DERIVED from the method's group
        // (PaymentMethodResolver) — a caller never names a driver.
        public string $paymentMethodCode,
        public int $amountCents,
        public ?int $tenderedCents,
        public ?string $reference,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
```

- [ ] **Step 6: Resolve and snapshot in `TakePayment`**

In `backend/app/Actions/Payments/TakePayment.php`: add `PaymentMethodResolver` as a constructor dependency, resolve after the shift lookup, and use the resolved driver everywhere `$in->driver` was used.

```php
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly PaymentMethodResolver $methods,
        private readonly AuditLogger $audit,
    ) {}
```

Add `use App\Domain\Payments\PaymentMethodResolver;` to the imports. Then, immediately after the `PaymentExceedsBalance` guard and before the `authorize()` call:

```php
            // Resolved inside the transaction, so an admin archiving a method mid-tender
            // loses the race rather than half-winning it.
            $method = $this->methods->resolve($locationId, $in->paymentMethodCode);

            $result = $this->drivers->driver($method->driver)->authorize(new PaymentIntent(
                amount: Money::fromCents($in->amountCents),
                tendered: $in->tenderedCents === null ? null : Money::fromCents($in->tenderedCents),
                reference: $in->reference,
            ));
```

In the `Payment::create([...])` array, replace `'driver' => $in->driver,` with:

```php
                'driver' => $method->driver,
                'payment_method_id' => $method->id,
                'payment_method_code' => $method->code,
                'payment_method_name' => $method->name,
```

And in the audit payload, replace `'driver' => $in->driver,` with:

```php
                'payment_method_code' => $method->code,
                'driver' => $method->driver,
```

- [ ] **Step 7: Update the request**

In `backend/app/Http/Requests/Payments/TakePaymentRequest.php`, replace the `driver` rule and the `toInput()` field:

```php
    public function rules(): array
    {
        return [
            // The set of legal codes is per-location DATA, so it is not an `in:` list —
            // PaymentMethodResolver is the gate, and it answers 422 unknown/inactive.
            'payment_method_code' => ['required', 'string', 'max:32'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'tendered_cents' => ['nullable', 'integer', 'min:1'],   // absent = exact tender
            'reference' => ['nullable', 'string', 'max:100'],
            'if_match' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
```

and in `toInput()`, replace `driver: $this->string('driver')->toString(),` with:

```php
            paymentMethodCode: $this->string('payment_method_code')->toString(),
```

- [ ] **Step 8: Update the response resource**

In `backend/app/Http/Resources/TakePaymentResource.php`, add the two snapshot fields to the `payment` array, after `'driver' => $this->driver,`:

```php
            'payment' => [
                'id' => $this->id,
                'driver' => $this->driver,
                'payment_method_code' => $this->payment_method_code,
                'payment_method_name' => $this->payment_method_name,
                'status' => $this->status,
                'amount_cents' => $this->amount_cents,
                'tendered_cents' => $this->tendered_cents,
                'change_cents' => $this->change_cents,
                'reference' => $this->reference,
            ],
```

- [ ] **Step 9: Add the `tenderColumns()` Pest helper**

Several tests build `Payment::create([...])` rows directly and now need the three not-null
columns. Add this to `backend/tests/Pest.php` (and `use App\Models\PaymentMethod;` to its
import block):

```php
/**
 * The three not-null tender columns a directly-created Payment/Refund row needs.
 * Spread it: `Payment::create([... , ...tenderColumns($location)])`.
 */
function tenderColumns(Location $location, string $code = 'CASH'): array
{
    $method = PaymentMethod::query()
        ->where('location_id', $location->id)
        ->where('code', $code)
        ->firstOrFail();

    return [
        'payment_method_id' => $method->id,
        'payment_method_code' => $method->code,
        'payment_method_name' => $method->name,
    ];
}
```

- [ ] **Step 10: Run the new test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Payments/PaymentMethodTenderTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 11: Fix the existing tests the rename broke**

Run: `cd backend && ./vendor/bin/pest`
Expected: failures in the files listed under **Files** above. Fix each mechanically — do not change what any test asserts, only how it names the tender:

1. **`TakePaymentInput` constructions** (`TakePaymentTest.php`, `ExternalCardTest.php`, `VoidPaymentTest.php`, `VoidOrderTest.php`, `ReopenOrderTest.php`, `DayTotalsTest.php`, `ReportsTest.php`, `ZReportTest.php`, `RefundOrderTest.php`): `driver: 'cash'` → `paymentMethodCode: 'CASH'`, `driver: 'external_card'` → `paymentMethodCode: 'CARD'`.
2. **JSON request bodies** posting to `/payments` (`TakePaymentTest.php:95`, `TakePaymentTest.php:115`, `AddLineModifiersTest.php:90`, `IdempotencyScopeTest.php:54,71`): `'driver' => 'cash'` → `'payment_method_code' => 'CASH'`.
3. **JSON request bodies** posting to `/refunds` (`RefundOrderTest.php:348,367,380,398`): leave these for Task 5, which changes that endpoint. If they fail now only because the *payment* setup above changed, fix the payment part and leave the refund body alone.
4. **Direct `Payment::create` / `Refund::create` calls** (`CloseShiftTest.php:33`, `ConstraintsTest.php:163`, `SettleZeroOrderTest.php:90`, `ExternalCardTest.php:121`): add `...tenderColumns($location)` (or `...tenderColumns($location, 'CARD')` where the row's driver is `external_card`) to the attribute array. `ConstraintsTest` builds its own location — use whatever `Location` variable is in scope there, and make sure it came from `provisionedLocation()` so the methods exist; if it used `Location::factory()`, switch it to `provisionedLocation()`.
5. **`ExternalCardTest.php:163`** builds an unsaved `new Payment([...])` to exercise a driver directly. An unsaved model needs no columns — leave it, but rename its `'driver' => 'external_card'` key only if the assertion under test reads it.

- [ ] **Step 12: Run the full backend suite**

Run: `make test-backend`
Expected: PASS, with 8 new tests.

- [ ] **Step 13: Commit**

```bash
git add backend/database/migrations/2026_07_26_000200_add_payment_method_to_ledgers.php \
        backend/app/Actions/Payments backend/app/Http/Requests/Payments \
        backend/app/Http/Resources/TakePaymentResource.php \
        backend/app/Models/Payment.php backend/app/Models/Refund.php \
        backend/tests/Pest.php backend/tests
git commit -m "feat(payments): tender on a per-location method code, driver derived"
```

---

## Task 5: Refunds on method codes, refundability from `Capabilities`

**Files:**
- Create: `backend/database/migrations/2026_07_26_000300_tighten_refund_payment_method.php`
- Create: `backend/app/Exceptions/Domain/RefundMethodNotRefundable.php`
- Modify: `backend/app/Actions/Refunds/RefundOrderInput.php`, `backend/app/Actions/Refunds/RefundOrder.php`
- Modify: `backend/app/Http/Requests/Refunds/RefundOrderRequest.php`
- Modify: `backend/app/Domain/Payments/ExternalCardDriver.php` (comment only)
- Modify: `backend/app/Http/Resources/RefundResource.php`
- Test: `backend/tests/Feature/Refunds/RefundMethodTest.php`, plus fixes to `tests/Feature/Refunds/RefundOrderTest.php`

**Interfaces:**
- Consumes: `PaymentMethodResolver` (Task 3), `DriverRegistry::driver()->capabilities()->refundable` (existing).
- Produces: `RefundOrderInput` with `public string $paymentMethodCode` in place of `public string $driver`; error code `refund_method_not_refundable`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Refunds/RefundMethodTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Refunds\RefundOrder;
use App\Actions\Refunds\RefundOrderInput;
use App\Actions\Refunds\RefundLineInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\PaymentMethodUnknown;
use App\Exceptions\Domain\RefundMethodNotRefundable;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use App\Models\ProductVariant;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->shift = Shift::factory()->create(['register_id' => $this->register->id]);
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 1000]);
    $this->order = Order::factory()->create([
        'location_id' => $this->location->id,
        'register_id' => $this->register->id,
        'shift_id' => $this->shift->id,
        'opened_by' => $this->supervisor->id,
        'status' => OrderStatus::Closed,
        'subtotal_cents' => 1000, 'total_cents' => 1000, 'paid_cents' => 1000,
    ]);
    $this->line = $this->order->lines()->create([
        'variant_id' => $variant->id,
        'name_snapshot' => 'Thing', 'sku_snapshot' => 'SKU-1',
        'qty' => '1', 'unit_price_cents' => 1000,
        'line_total_cents' => 1000, 'tax_cents' => 0, 'tax_rate_micros' => 0,
    ]);
});

function refundOn(object $t, string $code): \App\Models\Refund
{
    return app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $t->order->id,
        registerId: $t->register->id,
        paymentMethodCode: $code,
        reason: 'Faulty',
        lines: [new RefundLineInput(
            originalOrderLineId: $t->line->id, qty: '1', restock: false,
        )],
        actorId: $t->supervisor->id,
    ));
}

it('refunds cash and records the method', function (): void {
    $refund = refundOn($this, 'CASH');

    expect($refund->driver)->toBe('cash');
    expect($refund->payment_method_code)->toBe('CASH');
    expect($refund->payment_method_name)->toBe('Cash');
    expect($refund->amount_cents)->toBe(1000);
});

it('refuses a method whose driver is not refundable', function (): void {
    // The money never passed through us, so sending it back is a lie that would corrupt
    // both the drawer count and the card reconciliation. The rule now comes from
    // Capabilities::refundable rather than an `in:cash` validation string.
    expect(fn () => refundOn($this, 'CARD'))->toThrow(RefundMethodNotRefundable::class);
});

it('refuses a non-refundable method regardless of its group name', function (): void {
    $group = PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card',
    ]);
    PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $group->id,
        'code' => 'GCASH', 'name' => 'GCash',
    ]);

    expect(fn () => refundOn($this, 'GCASH'))->toThrow(RefundMethodNotRefundable::class);
});

it('refuses an unknown method', function (): void {
    expect(fn () => refundOn($this, 'MAYA'))->toThrow(PaymentMethodUnknown::class);
});

it('rejects a non-refundable method over HTTP with its own code', function (): void {
    $headers = staffHeaders($this->register, $this->supervisor)
        + ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()];

    $this->postJson('/api/v1/refunds', [
        'original_order_id' => $this->order->id,
        'payment_method_code' => 'CARD',
        'reason' => 'Faulty',
        'lines' => [['original_order_line_id' => $this->line->id, 'qty' => '1', 'restock' => false]],
    ], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'refund_method_not_refundable');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Refunds/RefundMethodTest.php`
Expected: FAIL — `Unknown named parameter $paymentMethodCode`.

- [ ] **Step 3: Write the exception**

Create `backend/app/Exceptions/Domain/RefundMethodNotRefundable.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * This method's driver cannot send money back through us.
 *
 * external_card is the case that exists today: a standalone terminal captured the card,
 * the money never passed through this system, and pretending we can return it would
 * corrupt both the drawer count and the card reconciliation. Sourced from
 * Capabilities::refundable, so any future driver inherits the rule without an edit.
 */
final class RefundMethodNotRefundable extends DomainException
{
    // NOT a promoted `$code` property: `Exception` already declares a non-readonly
    // `$code`, and redeclaring it readonly in a subclass is a fatal error. The sibling
    // PaymentMethodUnknown/PaymentMethodInactive exceptions use this same shape.
    private readonly string $methodCode;

    public function __construct(
        string $code,
        private readonly string $driver,
    ) {
        $this->methodCode = $code;
        parent::__construct("Payments taken on '{$code}' cannot be refunded through this system.");
    }

    public function errorCode(): string
    {
        return 'refund_method_not_refundable';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['payment_method_code' => $this->methodCode, 'driver' => $this->driver];
    }
}
```

- [ ] **Step 4: Rename the input field**

In `backend/app/Actions/Refunds/RefundOrderInput.php`, replace `public string $driver,` with `public string $paymentMethodCode,` in the same position (third).

- [ ] **Step 5: Resolve, gate on capabilities, and snapshot in `RefundOrder`**

In `backend/app/Actions/Refunds/RefundOrder.php`:

Add to the constructor and imports:

```php
    public function __construct(
        private readonly StockLedger $stock,
        private readonly PaymentMethodResolver $methods,
        private readonly DriverRegistry $drivers,
        private readonly AuditLogger $audit,
    ) {}
```

```php
use App\Domain\Payments\DriverRegistry;
use App\Domain\Payments\PaymentMethodResolver;
use App\Exceptions\Domain\RefundMethodNotRefundable;
```

Immediately after the `NoOpenShift` line (`$shift = Shift::openFor(...)`), before the "Pass 1" comment:

```php
            $method = $this->methods->resolve($register->location_id, $in->paymentMethodCode);

            // Refundability is a DRIVER capability, not a validation string: a driver
            // that cannot return money must not be refundable no matter what an admin
            // named the method.
            if (! $this->drivers->driver($method->driver)->capabilities()->refundable) {
                throw new RefundMethodNotRefundable($method->code, $method->driver);
            }
```

In the `Refund::create([...])` array, replace `'driver' => $in->driver,` with:

```php
                'driver' => $method->driver,
                'payment_method_id' => $method->id,
                'payment_method_code' => $method->code,
                'payment_method_name' => $method->name,
```

In the audit payload, add `'payment_method_code' => $method->code,` alongside the existing keys.

- [ ] **Step 6: Update the request**

In `backend/app/Http/Requests/Refunds/RefundOrderRequest.php`, replace the `driver` rule:

```php
            // Refundability is enforced in the action from Capabilities::refundable, not
            // by an `in:` list here — the legal set is per-location data, and the rule
            // belongs where the capability is declared. 422 refund_method_not_refundable.
            'payment_method_code' => ['required', 'string', 'max:32'],
```

and in `toInput()` replace `driver: $this->string('driver')->toString(),` with:

```php
            paymentMethodCode: $this->string('payment_method_code')->toString(),
```

- [ ] **Step 7: Update the `ExternalCardDriver` comment**

In `backend/app/Domain/Payments/ExternalCardDriver.php`, the `refund()` docblock says validation is the gate. Replace it so it names the real gate:

```php
    // Unreachable in practice: RefundOrder checks Capabilities::refundable before it
    // ever reaches a driver's refund(), so no request gets here. A driver that silently
    // claimed refundability anyway would corrupt reconciliation — better to fail loudly
    // here than let capabilities() and refund() disagree.
```

- [ ] **Step 8: Add the method to the refund resource**

In `backend/app/Http/Resources/RefundResource.php`, add the two snapshot fields next to the existing `'driver'` key:

```php
            'driver' => $this->driver,
            'payment_method_code' => $this->payment_method_code,
            'payment_method_name' => $this->payment_method_name,
```

- [ ] **Step 8a: Tighten the refund columns, now that a writer exists**

Task 4 deliberately left `refunds.payment_method_id/code/name` nullable, because tightening
a column before its writer exists leaves the refund path returning a raw `23502` — a dead
financial write path, not a task boundary. `RefundOrder` now writes all three, so create
`backend/database/migrations/2026_07_26_000300_tighten_refund_payment_method.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The other half of 2026_07_26_000200. That migration added these three columns to
 * `refunds` and backfilled them but left them nullable, because RefundOrder could not yet
 * write them. It can now, so the invariant becomes the schema's.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table refunds alter column payment_method_id set not null');
        DB::statement('alter table refunds alter column payment_method_code set not null');
        DB::statement('alter table refunds alter column payment_method_name set not null');
    }

    public function down(): void
    {
        DB::statement('alter table refunds alter column payment_method_id drop not null');
        DB::statement('alter table refunds alter column payment_method_code drop not null');
        DB::statement('alter table refunds alter column payment_method_name drop not null');
    }
};
```

- [ ] **Step 9: Run the new test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Refunds/RefundMethodTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 10: Fix `RefundOrderTest.php`**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Refunds`
In `tests/Feature/Refunds/RefundOrderTest.php`: `driver: 'cash'` → `paymentMethodCode: 'CASH'` in every `RefundOrderInput`, and `'driver' => 'cash'` → `'payment_method_code' => 'CASH'` in every JSON body. Line 398 posts `'driver' => 'external_card'` expecting a rejection — change it to `'payment_method_code' => 'CARD'` and update the expected error from the validation shape (`422` with a `driver` message) to `assertJsonPath('error.code', 'refund_method_not_refundable')`. The behaviour under test is unchanged; only which layer refuses it moved.

- [ ] **Step 11: Run the full backend suite**

Run: `make test-backend`
Expected: PASS.

- [ ] **Step 12: Commit**

```bash
git add backend/app/Actions/Refunds backend/app/Http/Requests/Refunds \
        backend/app/Http/Resources/RefundResource.php \
        backend/app/Exceptions/Domain/RefundMethodNotRefundable.php \
        backend/app/Domain/Payments/ExternalCardDriver.php \
        backend/tests/Feature/Refunds
git commit -m "feat(refunds): refund on a method code, refundability from driver capabilities"
```

---

## Task 6: Receipts print the method

**Files:**
- Modify: `backend/app/Http/Resources/ReceiptResource.php:61-66`
- Test: `backend/tests/Feature/Orders/ReceiptTest.php` (add one case)

**Interfaces:**
- Consumes: the `payment_method_code` / `payment_method_name` snapshot columns (Task 4).
- Produces: `payments[].payment_method_code` and `payments[].payment_method_name` on `GET /orders/{id}/receipt`.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Orders/ReceiptTest.php`. Its `beforeEach` gives
`$this->location`, `$this->register`, `$this->cashier` and `$this->order` with one line on
it, but no payment — so this case takes one:

```php
it('prints the method a tender was taken on, from the snapshot', function (): void {
    $headers = staffHeaders($this->register, $this->cashier);
    $order = Order::findOrFail($this->order->id);

    app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $order->id,
        registerId: $this->register->id,
        paymentMethodCode: 'CASH',
        amountCents: $order->total_cents,
        tenderedCents: $order->total_cents,
        reference: null,
        expectedVersion: $order->version,
        actorId: $this->cashier->id,
    ));

    $receipt = $this->getJson("/api/v1/orders/{$order->id}/receipt", $headers)
        ->assertOk()->json('data');

    expect($receipt['payments'][0]['payment_method_code'])->toBe('CASH');
    expect($receipt['payments'][0]['payment_method_name'])->toBe('Cash');

    // A receipt reprints identically forever — it reads the snapshot, never the live row.
    PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CASH')->update(['name' => 'Cash (peso)']);

    $again = $this->getJson("/api/v1/orders/{$order->id}/receipt", $headers)
        ->assertOk()->json('data');
    expect($again['payments'][0]['payment_method_name'])->toBe('Cash');
});
```

Add `use App\Actions\Payments\TakePayment;`, `use App\Actions\Payments\TakePaymentInput;`
and `use App\Models\PaymentMethod;` to the file's import block (`Order` is already there).

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Orders/ReceiptTest.php`
Expected: FAIL — undefined array key `payment_method_code`.

- [ ] **Step 3: Add the fields to the resource**

In `backend/app/Http/Resources/ReceiptResource.php`, extend the `payments` map:

```php
            'payments' => $this->payments->map(fn ($p): array => [
                'driver' => $p->driver,
                // Snapshots, not joins: a receipt from last year must reprint identically
                // even after the method has been renamed or archived.
                'payment_method_code' => $p->payment_method_code,
                'payment_method_name' => $p->payment_method_name,
                'amount_cents' => $p->amount_cents,
                'tendered_cents' => $p->tendered_cents,
                'change_cents' => $p->change_cents,
            ])->values()->all(),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Orders/ReceiptTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Resources/ReceiptResource.php backend/tests/Feature/Orders/ReceiptTest.php
git commit -m "feat(receipts): print the payment method from its snapshot"
```

---

## Task 7: The till's method list rides in `GET /catalog`

**Files:**
- Modify: `backend/app/Actions/Catalog/GetCatalog.php`, `backend/app/Actions/Catalog/CatalogSnapshot.php`
- Modify: `backend/app/Http/Resources/CatalogResource.php`
- Test: `backend/tests/Feature/Catalog/CatalogPaymentMethodsTest.php`

**Interfaces:**
- Consumes: `payment_method_groups` / `payment_methods` (Task 1).
- Produces: `CatalogSnapshot::$paymentMethods` (a `list<array{id,code,name,group_code,group_name,driver,sort_order}>`) and the `payment_methods` key on `GET /catalog`. Tasks 15 and 16 consume this wire shape.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Catalog/CatalogPaymentMethodsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->headers = staffHeaders($this->register, $this->cashier);
});

it('carries the location\'s active methods in group then method order', function (): void {
    $ewallet = PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card', 'sort_order' => 2,
    ]);
    PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $ewallet->id,
        'code' => 'MAYA', 'name' => 'Maya', 'sort_order' => 1,
    ]);
    PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $ewallet->id,
        'code' => 'GCASH', 'name' => 'GCash', 'sort_order' => 0,
    ]);

    $methods = $this->getJson('/api/v1/catalog', $this->headers)->assertOk()
        ->json('data.payment_methods');

    // CASH (group sort 0), CARD (1), then the e-wallet group's two by their own sort.
    expect(array_column($methods, 'code'))->toBe(['CASH', 'CARD', 'GCASH', 'MAYA']);
    expect($methods[2]['group_code'])->toBe('EWALLET');
    expect($methods[2]['group_name'])->toBe('E-wallets');
    expect($methods[2]['driver'])->toBe('external_card');
});

it('omits archived methods', function (): void {
    PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CARD')->update(['is_active' => false]);

    $methods = $this->getJson('/api/v1/catalog', $this->headers)->assertOk()
        ->json('data.payment_methods');

    expect(array_column($methods, 'code'))->toBe(['CASH']);
});

it('omits every method under an archived group', function (): void {
    // One switch for "we stopped taking cards" — the method rows are untouched.
    PaymentMethodGroup::query()->where('location_id', $this->location->id)
        ->where('code', 'CARD')->update(['is_active' => false]);

    $methods = $this->getJson('/api/v1/catalog', $this->headers)->assertOk()
        ->json('data.payment_methods');

    expect(array_column($methods, 'code'))->toBe(['CASH']);
    expect(PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CARD')->value('is_active'))->toBeTrue();
});

it('never leaks another location\'s methods', function (): void {
    $other = provisionedLocation(['code' => 'ZZZ']);
    $group = PaymentMethodGroup::factory()->create([
        'location_id' => $other->id, 'code' => 'EWALLET', 'driver' => 'external_card',
    ]);
    PaymentMethod::factory()->create([
        'location_id' => $other->id, 'group_id' => $group->id, 'code' => 'GCASH', 'name' => 'GCash',
    ]);

    $methods = $this->getJson('/api/v1/catalog', $this->headers)->assertOk()
        ->json('data.payment_methods');

    expect(array_column($methods, 'code'))->not->toContain('GCASH');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Catalog/CatalogPaymentMethodsTest.php`
Expected: FAIL — `payment_methods` is null.

- [ ] **Step 3: Add the field to the snapshot**

In `backend/app/Actions/Catalog/CatalogSnapshot.php`, append a constructor property (last, so no existing positional call breaks):

```php
final readonly class CatalogSnapshot
{
    public function __construct(
        public array $categories,
        public array $products,
        public array $variants,
        public array $modifierGroups,
        public array $modifiers,
        public array $taxRates,
        public array $discounts,
        public array $paymentMethods,
    ) {}
}
```

- [ ] **Step 4: Query it in `GetCatalog`**

In `backend/app/Actions/Catalog/GetCatalog.php`, add the argument to the `new CatalogSnapshot(...)` call:

```php
            // The tender buttons the till renders. Location-scoped like prices above;
            // active methods in ACTIVE groups only. A total order (group sort, group
            // code, method sort, method code) so two rows sharing a sort value never
            // render in a different sequence per request.
            paymentMethods: DB::table('payment_methods as pm')
                ->join('payment_method_groups as g', 'g.id', '=', 'pm.group_id')
                ->where('pm.location_id', $locationId)
                ->where('pm.is_active', true)
                ->where('g.is_active', true)
                ->orderBy('g.sort_order')->orderBy('g.code')
                ->orderBy('pm.sort_order')->orderBy('pm.code')
                ->get([
                    'pm.id', 'pm.code', 'pm.name', 'pm.sort_order',
                    'g.code as group_code', 'g.name as group_name', 'g.driver',
                ])
                ->map(fn ($r): array => (array) $r)->all(),
```

- [ ] **Step 5: Expose it on the resource**

In `backend/app/Http/Resources/CatalogResource.php`, add before `'currency'`:

```php
            'payment_methods' => $this->paymentMethods,
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Catalog/CatalogPaymentMethodsTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 7: Run the backend suite**

Run: `make test-backend`
Expected: PASS. If any test constructs `CatalogSnapshot` positionally, add the eighth argument.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Actions/Catalog backend/app/Http/Resources/CatalogResource.php \
        backend/tests/Feature/Catalog/CatalogPaymentMethodsTest.php
git commit -m "feat(catalog): carry the location's payment methods in the till payload"
```

---

## Task 8: Z-report by method and by group

**Files:**
- Modify: `backend/app/Actions/Reports/ZReport.php`, `backend/app/Actions/Reports/GetZReport.php`
- Modify: `backend/app/Http/Resources/ZReportResource.php`
- Test: `backend/tests/Feature/Reports/ZReportTest.php` (replace the driver-map assertions, add a two-groups-one-driver case)

**Interfaces:**
- Consumes: the ledger snapshot columns (Task 4).
- Produces: `ZReport` with `array $salesByMethod, $salesByGroup, $refundsByMethod, $refundsByGroup` **in place of** `$salesByDriver, $refundsByDriver`; the same four keys on `GET /reports/z`. Task 15 consumes the wire shape.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Reports/ZReportTest.php`. This file's `beforeEach` already
gives `$this->location`, `$this->register`, `$this->cashier` and `$this->shift` (from
`OpenShift`), so the cases below build their own paid orders rather than depending on a
helper's shape:

```php
/** A closed order paid in full on one method, in the open shift. Own name — Pest's
 *  file-scoped functions collide across files, and this file already namespaces its own. */
function zmTenderedOrder(object $t, string $methodCode, int $cents): void
{
    // forRegister() wires location/register/shift into one chain and computes
    // business_date in the LOCATION's timezone, which ledger-basis reports depend on.
    $order = Order::factory()->forRegister($t->register)->create([
        'opened_by' => $t->cashier->id,
        'subtotal_cents' => $cents,
        'total_cents' => $cents,
    ]);

    app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $order->id,
        registerId: $t->register->id,
        paymentMethodCode: $methodCode,
        amountCents: $cents,
        tenderedCents: $cents,
        reference: null,
        expectedVersion: $order->version,
        actorId: $t->cashier->id,
    ));
}

it('breaks sales down by method and rolls them up by group', function (): void {
    // Two groups sharing one driver — the whole reason the rollup is by GROUP and not by
    // driver: a supervisor counting a drawer needs GCash apart from Visa.
    $ewallet = \App\Models\PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card', 'sort_order' => 2,
    ]);
    \App\Models\PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $ewallet->id,
        'code' => 'GCASH', 'name' => 'GCash',
    ]);

    zmTenderedOrder($this, 'CASH', 1000);
    zmTenderedOrder($this, 'CARD', 2000);
    zmTenderedOrder($this, 'GCASH', 3000);

    $z = app(GetZReport::class)->execute($this->shift->id, $this->register->id);

    expect($z->salesByMethod)->toEqual(['CASH' => 1000, 'CARD' => 2000, 'GCASH' => 3000]);
    // GCash rolls into EWALLET, not into CARD, even though both drive external_card.
    expect($z->salesByGroup)->toEqual(['CASH' => 1000, 'CARD' => 2000, 'EWALLET' => 3000]);
});

it('omits methods with no activity', function (): void {
    zmTenderedOrder($this, 'CASH', 1000);

    // Same shape the driver maps had: only codes with money against them appear.
    $z = app(GetZReport::class)->execute($this->shift->id, $this->register->id);

    expect($z->salesByMethod)->not->toHaveKey('CARD');
    expect($z->salesByGroup)->not->toHaveKey('CARD');
});
```

`toEqual`, not `toBe`, on both maps: `pluck` order follows Postgres's grouping, which is not
a promise.

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Reports/ZReportTest.php`
Expected: FAIL — `Undefined property: App\Actions\Reports\ZReport::$salesByMethod`.

- [ ] **Step 3: Rewrite the `ZReport` value object**

Replace the four driver properties in `backend/app/Actions/Reports/ZReport.php`:

```php
final readonly class ZReport
{
    /**
     * @param  array<string, int>  $salesByMethod    method code => captured cents
     * @param  array<string, int>  $salesByGroup     group code  => captured cents
     * @param  array<string, int>  $refundsByMethod
     * @param  array<string, int>  $refundsByGroup
     * @param  array{paid_in: int, payout: int, drop: int}  $movements
     */
    public function __construct(
        public Shift $shift,
        public array $salesByMethod,
        public array $salesByGroup,
        public array $refundsByMethod,
        public array $refundsByGroup,
        public array $movements,
        public int $ordersClosed,
        public int $ordersVoided,
        public int $ordersSplit,
        public int $expectedCashCents,
    ) {}
}
```

- [ ] **Step 4: Group by method and group in `GetZReport`**

In `backend/app/Actions/Reports/GetZReport.php`, replace the two driver queries with four. Both group on the **snapshot** code for the method (stable against a rename) and join for the group code (a method's `group_id` is immutable, so the join is stable too):

```php
        $salesByMethod = DB::table('payments')
            ->where('shift_id', $shift->id)
            ->where('status', 'captured')
            ->groupBy('payment_method_code')
            ->selectRaw('payment_method_code as code, sum(amount_cents) as cents')
            ->pluck('cents', 'code')
            ->map(static fn (mixed $cents): int => (int) $cents)
            ->all();

        // Rolled up by GROUP, not by driver: two groups may share a driver (CARD and
        // EWALLET both drive external_card) and a drawer count needs them apart. Joined
        // through the method FK rather than grouped on a snapshot, because a method's
        // group_id is immutable — the join is as stable as a snapshot would be.
        $salesByGroup = DB::table('payments as p')
            ->join('payment_methods as pm', 'pm.id', '=', 'p.payment_method_id')
            ->join('payment_method_groups as g', 'g.id', '=', 'pm.group_id')
            ->where('p.shift_id', $shift->id)
            ->where('p.status', 'captured')
            ->groupBy('g.code')
            ->selectRaw('g.code as code, sum(p.amount_cents) as cents')
            ->pluck('cents', 'code')
            ->map(static fn (mixed $cents): int => (int) $cents)
            ->all();

        $refundsByMethod = DB::table('refunds')
            ->where('shift_id', $shift->id)
            ->groupBy('payment_method_code')
            ->selectRaw('payment_method_code as code, sum(amount_cents) as cents')
            ->pluck('cents', 'code')
            ->map(static fn (mixed $cents): int => (int) $cents)
            ->all();

        $refundsByGroup = DB::table('refunds as r')
            ->join('payment_methods as pm', 'pm.id', '=', 'r.payment_method_id')
            ->join('payment_method_groups as g', 'g.id', '=', 'pm.group_id')
            ->where('r.shift_id', $shift->id)
            ->groupBy('g.code')
            ->selectRaw('g.code as code, sum(r.amount_cents) as cents')
            ->pluck('cents', 'code')
            ->map(static fn (mixed $cents): int => (int) $cents)
            ->all();
```

Then pass all four to the `new ZReport(...)` call in place of the two driver arguments,
in the constructor order from Step 3 (`salesByMethod`, `salesByGroup`, `refundsByMethod`,
`refundsByGroup`).

- [ ] **Step 5: Update the resource**

In `backend/app/Http/Resources/ZReportResource.php`, replace the two driver keys:

```php
            'sales_by_method' => $this->salesByMethod,
            'sales_by_group' => $this->salesByGroup,
            'refunds_by_method' => $this->refundsByMethod,
            'refunds_by_group' => $this->refundsByGroup,
```

- [ ] **Step 6: Update the existing driver-map assertions**

In `tests/Feature/Reports/ZReportTest.php`, every `$z->salesByDriver` / `$z->refundsByDriver` becomes `$z->salesByMethod` / `$z->refundsByMethod`, and every `'cash'` / `'external_card'` key becomes `'CASH'` / `'CARD'`. Any HTTP assertion on `data.sales_by_driver` becomes `data.sales_by_method`. The numbers do not change.

- [ ] **Step 7: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Reports`
Expected: PASS.

- [ ] **Step 8: Run the full backend suite**

Run: `make test-backend`
Expected: PASS. `tests/Feature/Day/DayTotalsTest.php` may also read the driver maps — update it the same way.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Actions/Reports backend/app/Http/Resources/ZReportResource.php backend/tests
git commit -m "feat(reports): Z-report breaks tenders down by method and group"
```

---

## Task 9: Admin CRUD for payment method groups

**Files:**
- Create: `backend/app/Actions/Admin/PaymentMethods/ListPaymentMethodGroups.php`, `ListPaymentMethodGroupsInput.php`, `CreatePaymentMethodGroup.php`, `CreatePaymentMethodGroupInput.php`, `UpdatePaymentMethodGroup.php`, `UpdatePaymentMethodGroupInput.php`
- Create: `backend/app/Http/Requests/Concerns/ScopesToPermittedLocation.php`
- Create: `backend/app/Http/Requests/Admin/PaymentMethods/ListPaymentMethodGroupsRequest.php`, `CreatePaymentMethodGroupRequest.php`, `UpdatePaymentMethodGroupRequest.php`
- Create: `backend/app/Http/Controllers/Admin/PaymentMethods/ListPaymentMethodGroupsController.php`, `CreatePaymentMethodGroupController.php`, `UpdatePaymentMethodGroupController.php`
- Create: `backend/app/Http/Resources/Admin/AdminPaymentMethodGroupResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/PaymentMethodGroupCrudTest.php`

**Interfaces:**
- Consumes: `Permissions::PAYMENT_METHOD_MANAGE` (Task 2), `PaymentMethodGroup` (Task 1), `AuthorizesBackOffice::allowsBackOffice()` and `AdminAccess::locationIdsWhere()` (existing).
- Produces: `GET|POST /api/v1/admin/payment-method-groups`, `PATCH /api/v1/admin/payment-method-groups/{group}`; route names `admin.payment-method-groups.list|create|update`; audit actions `admin.payment_method_group.create|update`. Task 13 consumes the wire shape.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Admin/PaymentMethodGroupCrudTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Rbac\Permissions;
use App\Models\Location;
use App\Models\PaymentMethodGroup;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'AAA']);
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

it('creates, lists, and archives a group', function (): void {
    $created = $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $this->location->id,
        'code' => 'ewallet', 'name' => 'E-wallets', 'driver' => 'external_card', 'sort_order' => 2,
    ], $this->headers)->assertCreated();

    // Codes are normalized to uppercase — they are wire identifiers and report keys.
    expect($created->json('data.payment_method_group.code'))->toBe('EWALLET');
    $id = $created->json('data.payment_method_group.id');

    $this->getJson("/api/v1/admin/payment-method-groups?location_id={$this->location->id}", $this->headers)
        ->assertOk()
        ->assertJsonPath('data.items.2.code', 'EWALLET');   // after the CASH/CARD defaults

    $this->patchJson("/api/v1/admin/payment-method-groups/{$id}", ['is_active' => false], $this->headers)
        ->assertOk();
    expect(PaymentMethodGroup::findOrFail($id)->is_active)->toBeFalse();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.payment_method_group.create', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.payment_method_group.update', 'entity_id' => $id]);
});

it('refuses a duplicate code at one location', function (): void {
    $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $this->location->id,
        'code' => 'CASH', 'name' => 'Cash again', 'driver' => 'cash',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('accepts the same code at a different location', function (): void {
    $other = provisionedLocation(['code' => 'BBB']);

    $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $other->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card',
    ], $this->headers)->assertCreated();
    $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card',
    ], $this->headers)->assertCreated();

    expect(PaymentMethodGroup::query()->where('code', 'EWALLET')->count())->toBe(2);
});

it('refuses a driver outside the registry', function (): void {
    $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $this->location->id,
        'code' => 'CRYPTO', 'name' => 'Crypto', 'driver' => 'bitcoin',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('ignores code, driver and location on update', function (): void {
    $group = PaymentMethodGroup::query()->where('location_id', $this->location->id)
        ->where('code', 'CARD')->firstOrFail();
    $other = provisionedLocation(['code' => 'BBB']);

    $this->patchJson("/api/v1/admin/payment-method-groups/{$group->id}", [
        'name' => 'Bank cards',
        'code' => 'PLASTIC', 'driver' => 'cash', 'location_id' => $other->id,
    ], $this->headers)->assertOk();

    $group->refresh();
    // The name is display copy; the code is a report key and the driver IS the behaviour.
    expect($group->name)->toBe('Bank cards');
    expect($group->code)->toBe('CARD');
    expect($group->driver)->toBe('external_card');
    expect($group->location_id)->toBe($this->location->id);
});

it('has no delete route', function (): void {
    $group = PaymentMethodGroup::query()->where('location_id', $this->location->id)->firstOrFail();

    $this->deleteJson("/api/v1/admin/payment-method-groups/{$group->id}", [], $this->headers)
        ->assertStatus(405);
});

it('refuses a session without the permission', function (): void {
    $nobody = User::factory()->create(['email' => 'n@pos.test', 'password_hash' => 'pw']);
    $headers = ['Authorization' => 'Bearer '.$nobody->createToken('t')->plainTextToken];

    $this->getJson("/api/v1/admin/payment-method-groups?location_id={$this->location->id}", $headers)
        ->assertStatus(403);
});

it('scopes a non-admin holder to the locations they hold it at', function (): void {
    $other = provisionedLocation(['code' => 'BBB']);
    $manager = User::factory()->create(['email' => 'm@pos.test', 'password_hash' => 'pw']);

    // Direct grant at ONE location — holding it somewhere gets you into the section, not
    // into every store's tenders (docs/05-rbac.md).
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->location->id);
    $manager->givePermissionTo(Permissions::PAYMENT_METHOD_MANAGE);
    $registrar->forgetCachedPermissions();

    $headers = ['Authorization' => 'Bearer '.$manager->createToken('t')->plainTextToken];

    $this->getJson("/api/v1/admin/payment-method-groups?location_id={$this->location->id}", $headers)
        ->assertOk();
    $this->getJson("/api/v1/admin/payment-method-groups?location_id={$other->id}", $headers)
        ->assertStatus(403);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/PaymentMethodGroupCrudTest.php`
Expected: FAIL — 404, the routes do not exist.

- [ ] **Step 3: Write the three Input DTOs**

`backend/app/Actions/Admin/PaymentMethods/ListPaymentMethodGroupsInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class ListPaymentMethodGroupsInput
{
    public function __construct(public string $locationId) {}
}
```

`backend/app/Actions/Admin/PaymentMethods/CreatePaymentMethodGroupInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class CreatePaymentMethodGroupInput
{
    public function __construct(
        public string $locationId,
        public string $code,
        public string $name,
        public string $driver,
        public int $sortOrder,
        public bool $isActive,
        public string $actorId,
    ) {}
}
```

`backend/app/Actions/Admin/PaymentMethods/UpdatePaymentMethodGroupInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class UpdatePaymentMethodGroupInput
{
    /** @param array<string, mixed> $changes only name, sort_order, is_active ever reach here */
    public function __construct(
        public string $groupId,
        public array $changes,
        public string $actorId,
    ) {}
}
```

- [ ] **Step 4: Write the three actions**

`backend/app/Actions/Admin/PaymentMethods/ListPaymentMethodGroups.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Models\PaymentMethodGroup;
use Illuminate\Database\Eloquent\Collection;

/** Unpaginated, like every admin list in v1. Ordered as the till renders them. */
final class ListPaymentMethodGroups
{
    /** @return Collection<int, PaymentMethodGroup> */
    public function execute(ListPaymentMethodGroupsInput $in): Collection
    {
        return PaymentMethodGroup::query()
            ->where('location_id', $in->locationId)
            ->with(['methods' => fn ($query) => $query->orderBy('sort_order')->orderBy('code')])
            ->orderBy('sort_order')->orderBy('code')
            ->get();
    }
}
```

`backend/app/Actions/Admin/PaymentMethods/CreatePaymentMethodGroup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Domain\Audit\AuditLogger;
use App\Models\PaymentMethodGroup;
use Illuminate\Support\Facades\DB;

final class CreatePaymentMethodGroup
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreatePaymentMethodGroupInput $in): PaymentMethodGroup
    {
        return DB::transaction(function () use ($in): PaymentMethodGroup {
            $group = PaymentMethodGroup::create([
                'location_id' => $in->locationId,
                'code' => $in->code,
                'name' => $in->name,
                'driver' => $in->driver,
                'sort_order' => $in->sortOrder,
                'is_active' => $in->isActive,
            ]);

            $this->audit->record('admin.payment_method_group.create', $group, $in->actorId, [
                'location_id' => $in->locationId, 'code' => $in->code, 'driver' => $in->driver,
            ]);

            return $group;
        });
    }
}
```

`backend/app/Actions/Admin/PaymentMethods/UpdatePaymentMethodGroup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Domain\Audit\AuditLogger;
use App\Models\PaymentMethodGroup;
use Illuminate\Support\Facades\DB;

/**
 * Name, sort order and archive only. `code` and `driver` never arrive here — the code is
 * a wire identifier and a report key, and the driver IS the behaviour every method under
 * the group inherits. Changing either is archive-and-recreate.
 */
final class UpdatePaymentMethodGroup
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdatePaymentMethodGroupInput $in): PaymentMethodGroup
    {
        return DB::transaction(function () use ($in): PaymentMethodGroup {
            $group = PaymentMethodGroup::query()->lockForUpdate()->findOrFail($in->groupId);

            $group->fill($in->changes)->save();

            $this->audit->record('admin.payment_method_group.update', $group, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $group;
        });
    }
}
```

- [ ] **Step 5: Write the resource**

`backend/app/Http/Resources/Admin/AdminPaymentMethodGroupResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PaymentMethodGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentMethodGroup */
final class AdminPaymentMethodGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'code' => $this->code,
            'name' => $this->name,
            'driver' => $this->driver,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            // Nested when the list eager-loaded them, so the section renders in one call.
            'methods' => AdminPaymentMethodResource::collection($this->whenLoaded('methods')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

(`AdminPaymentMethodResource` is created in Task 10. Until then the `whenLoaded` line
will fail to autoload — write Task 10's resource file first if you are running Task 9
standalone, or accept that Step 8 below is the gate for both.)

- [ ] **Step 6a: Write the location-scoping concern**

All six FormRequests in this task and Task 10 need the same check, so it lives in one
trait beside `AuthorizesBackOffice` rather than being copy-pasted six times. Create
`backend/app/Http/Requests/Concerns/ScopesToPermittedLocation.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Domain\Rbac\AdminAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Refuse a location the caller does not hold the permission at.
 *
 * The back-office login gate (AdminAccess::holdsAnywhere) is deliberately "anywhere" —
 * holding an admin-tier permission at one location is what gets a non-admin into the
 * section at all. It is NOT a blank cheque over every location's data, which is why every
 * location-scoped admin request re-checks the specific location (docs/05-rbac.md). Admins
 * are exempt by definition: locationIdsWhere() returns null (all locations) for them.
 *
 * A `null` locationId — an unknown row id on an update — fails closed, which also avoids
 * leaking whether the row exists.
 */
trait ScopesToPermittedLocation
{
    protected function assertLocationPermitted(?string $locationId, string $permission): void
    {
        $user = $this->user();

        if (! $user instanceof User || $user->is_admin) {
            return;
        }

        $allowed = app(AdminAccess::class)->locationIdsWhere($user, $permission) ?? [];

        if ($locationId === null || ! in_array($locationId, $allowed, true)) {
            throw new AuthorizationException;
        }
    }
}
```

- [ ] **Step 6b: Write the three FormRequests**

`backend/app/Http/Requests/Admin/PaymentMethods/ListPaymentMethodGroupsRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\ListPaymentMethodGroupsInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class ListPaymentMethodGroupsRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

    public function rules(): array
    {
        return ['location_id' => ['required', 'uuid', 'exists:locations,id']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn () => $this->assertLocationPermitted(
            $this->string('location_id')->toString(),
            Permissions::PAYMENT_METHOD_MANAGE,
        ));
    }

    public function toInput(): ListPaymentMethodGroupsInput
    {
        return new ListPaymentMethodGroupsInput(
            locationId: $this->string('location_id')->toString(),
        );
    }
}
```

`backend/app/Http/Requests/Admin/PaymentMethods/CreatePaymentMethodGroupRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\CreatePaymentMethodGroupInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreatePaymentMethodGroupRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

    /** Codes are wire identifiers and report keys — normalized, never free-form casing. */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9_]+$/',
                // Unique PER LOCATION — the same code at another store is legal and
                // expected. The partial-scope unique index is the real gate; this turns
                // a 500 into a 422 with a field message.
                Rule::unique('payment_method_groups', 'code')
                    ->where(fn ($query) => $query->where('location_id', $this->input('location_id'))),
            ],
            'name' => ['required', 'string', 'max:200'],
            // The driver is the code seam, so the legal set is code, not data.
            'driver' => ['required', 'string', 'in:cash,external_card'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn () => $this->assertLocationPermitted(
            $this->string('location_id')->toString(),
            Permissions::PAYMENT_METHOD_MANAGE,
        ));
    }

    public function toInput(): CreatePaymentMethodGroupInput
    {
        return new CreatePaymentMethodGroupInput(
            locationId: $this->string('location_id')->toString(),
            code: $this->string('code')->toString(),
            name: $this->string('name')->toString(),
            driver: $this->string('driver')->toString(),
            sortOrder: $this->integer('sort_order', 0),
            isActive: $this->boolean('is_active', true),
            actorId: $this->user()->id,
        );
    }
}
```

`backend/app/Http/Requests/Admin/PaymentMethods/UpdatePaymentMethodGroupRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\UpdatePaymentMethodGroupInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use App\Models\PaymentMethodGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePaymentMethodGroupRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

    /**
     * `code`, `driver` and `location_id` are absent from the rules on purpose, so
     * `safe()->only()` drops them: they are immutable after create. A client that sends
     * one is ignored rather than 422'd — the same shape every other admin PATCH has,
     * which applies only the keys it recognizes.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Scoped against the ROW's location — an update names no location itself.
        $validator->after(fn () => $this->assertLocationPermitted(
            PaymentMethodGroup::query()->whereKey((string) $this->route('group'))->value('location_id'),
            Permissions::PAYMENT_METHOD_MANAGE,
        ));
    }

    public function toInput(): UpdatePaymentMethodGroupInput
    {
        return new UpdatePaymentMethodGroupInput(
            groupId: (string) $this->route('group'),
            changes: $this->safe()->only(['name', 'sort_order', 'is_active']),
            actorId: $this->user()->id,
        );
    }
}
```

- [ ] **Step 7: Write the three controllers**

`ListPaymentMethodGroupsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\ListPaymentMethodGroups;
use App\Http\Requests\Admin\PaymentMethods\ListPaymentMethodGroupsRequest;
use App\Http\Resources\Admin\AdminPaymentMethodGroupResource;
use Illuminate\Http\JsonResponse;

final class ListPaymentMethodGroupsController
{
    public function __invoke(ListPaymentMethodGroupsRequest $request, ListPaymentMethodGroups $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminPaymentMethodGroupResource::collection($action->execute($request->toInput()))],
        ]);
    }
}
```

`CreatePaymentMethodGroupController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\CreatePaymentMethodGroup;
use App\Http\Requests\Admin\PaymentMethods\CreatePaymentMethodGroupRequest;
use App\Http\Resources\Admin\AdminPaymentMethodGroupResource;
use Illuminate\Http\JsonResponse;

final class CreatePaymentMethodGroupController
{
    public function __invoke(CreatePaymentMethodGroupRequest $request, CreatePaymentMethodGroup $action): JsonResponse
    {
        return response()->json([
            'data' => ['payment_method_group' => new AdminPaymentMethodGroupResource($action->execute($request->toInput()))],
        ], 201);
    }
}
```

`UpdatePaymentMethodGroupController.php`: identical to the create controller but
`UpdatePaymentMethodGroup` / `UpdatePaymentMethodGroupRequest` and no `201` second
argument to `response()->json(...)`.

- [ ] **Step 8: Register the routes**

In `backend/routes/api.php`, inside the existing `Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])` group — after the locations/registers block, before reports — add:

```php
        // Per-location tender taxonomy. Gated payment_method.manage; archive via
        // PATCH is_active, no DELETE, same as catalog. Every mutation audits
        // admin.payment_method_group.create|update / admin.payment_method.create|update.
        Route::get('/payment-method-groups', ListPaymentMethodGroupsController::class)
            ->name('admin.payment-method-groups.list');
        Route::post('/payment-method-groups', CreatePaymentMethodGroupController::class)
            ->name('admin.payment-method-groups.create');
        Route::patch('/payment-method-groups/{group}', UpdatePaymentMethodGroupController::class)
            ->name('admin.payment-method-groups.update');
```

Add the three `use App\Http\Controllers\Admin\PaymentMethods\...Controller;` imports at the top, in the alphabetical position the file already uses.

- [ ] **Step 9: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/PaymentMethodGroupCrudTest.php`
Expected: PASS, 8 tests. (Requires Task 10's `AdminPaymentMethodResource` to exist — create that one file now if it does not.)

- [ ] **Step 10: Commit**

```bash
git add backend/app/Actions/Admin/PaymentMethods backend/app/Http/Requests/Admin/PaymentMethods \
        backend/app/Http/Controllers/Admin/PaymentMethods backend/app/Http/Resources/Admin \
        backend/routes/api.php backend/tests/Feature/Admin/PaymentMethodGroupCrudTest.php
git commit -m "feat(back-office): CRUD for payment method groups"
```

---

## Task 10: Admin CRUD for payment methods

**Files:**
- Create: `backend/app/Actions/Admin/PaymentMethods/ListPaymentMethods.php`, `ListPaymentMethodsInput.php`, `CreatePaymentMethod.php`, `CreatePaymentMethodInput.php`, `UpdatePaymentMethod.php`, `UpdatePaymentMethodInput.php`
- Create: `backend/app/Http/Requests/Admin/PaymentMethods/ListPaymentMethodsRequest.php`, `CreatePaymentMethodRequest.php`, `UpdatePaymentMethodRequest.php`
- Create: `backend/app/Http/Controllers/Admin/PaymentMethods/ListPaymentMethodsController.php`, `CreatePaymentMethodController.php`, `UpdatePaymentMethodController.php`
- Create: `backend/app/Http/Resources/Admin/AdminPaymentMethodResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/PaymentMethodCrudTest.php`

**Interfaces:**
- Consumes: `Permissions::PAYMENT_METHOD_MANAGE` (Task 2), `PaymentMethod` / `PaymentMethodGroup` (Task 1).
- Produces: `GET|POST /api/v1/admin/payment-methods`, `PATCH /api/v1/admin/payment-methods/{method}`; route names `admin.payment-methods.list|create|update`; audit actions `admin.payment_method.create|update`; `AdminPaymentMethodResource`. Task 13 consumes the wire shape; Task 9's group resource nests this one.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Admin/PaymentMethodCrudTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use App\Models\User;

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'AAA']);
    $this->cardGroup = PaymentMethodGroup::query()
        ->where('location_id', $this->location->id)->where('code', 'CARD')->firstOrFail();
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

it('creates, lists, and archives a method', function (): void {
    $created = $this->postJson('/api/v1/admin/payment-methods', [
        'location_id' => $this->location->id,
        'group_id' => $this->cardGroup->id,
        'code' => 'visa', 'name' => 'Visa', 'sort_order' => 1,
    ], $this->headers)->assertCreated();

    expect($created->json('data.payment_method.code'))->toBe('VISA');
    $id = $created->json('data.payment_method.id');

    $this->getJson("/api/v1/admin/payment-methods?location_id={$this->location->id}", $this->headers)
        ->assertOk()
        ->assertJsonFragment(['code' => 'VISA']);

    $this->patchJson("/api/v1/admin/payment-methods/{$id}", ['is_active' => false], $this->headers)
        ->assertOk();
    expect(PaymentMethod::findOrFail($id)->is_active)->toBeFalse();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.payment_method.create', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.payment_method.update', 'entity_id' => $id]);
});

it('derives location from the group and refuses a group at another location', function (): void {
    $other = provisionedLocation(['code' => 'BBB']);
    $groupAtOther = PaymentMethodGroup::query()
        ->where('location_id', $other->id)->where('code', 'CARD')->firstOrFail();

    // The composite FK would reject this at the database; the request refuses it first
    // with a field message rather than a 500.
    $this->postJson('/api/v1/admin/payment-methods', [
        'location_id' => $this->location->id,
        'group_id' => $groupAtOther->id,
        'code' => 'VISA', 'name' => 'Visa',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a duplicate code at one location even across groups', function (): void {
    // CASH already exists under the location's CASH group. Posting it under the CARD
    // group is what proves the index is scoped to the LOCATION and not to the group —
    // a per-group index would happily accept this.
    $this->postJson('/api/v1/admin/payment-methods', [
        'location_id' => $this->location->id,
        'group_id' => $this->cardGroup->id,
        'code' => 'CASH', 'name' => 'Cash again',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('accepts the same code at a different location', function (): void {
    $other = provisionedLocation(['code' => 'BBB']);
    $groupAtOther = PaymentMethodGroup::query()
        ->where('location_id', $other->id)->where('code', 'CARD')->firstOrFail();

    foreach ([[$this->location, $this->cardGroup], [$other, $groupAtOther]] as [$location, $group]) {
        $this->postJson('/api/v1/admin/payment-methods', [
            'location_id' => $location->id, 'group_id' => $group->id,
            'code' => 'VISA', 'name' => 'Visa',
        ], $this->headers)->assertCreated();
    }

    expect(PaymentMethod::query()->where('code', 'VISA')->count())->toBe(2);
});

it('ignores code and group on update', function (): void {
    $method = PaymentMethod::query()
        ->where('location_id', $this->location->id)->where('code', 'CARD')->firstOrFail();
    $cash = PaymentMethodGroup::query()
        ->where('location_id', $this->location->id)->where('code', 'CASH')->firstOrFail();

    $this->patchJson("/api/v1/admin/payment-methods/{$method->id}", [
        'name' => 'Bank card', 'sort_order' => 5,
        'code' => 'PLASTIC', 'group_id' => $cash->id,
    ], $this->headers)->assertOk();

    $method->refresh();
    expect($method->name)->toBe('Bank card');
    expect($method->sort_order)->toBe(5);
    expect($method->code)->toBe('CARD');
    // Moving a method between groups would change its DRIVER and retroactively re-bucket
    // every historical payment taken on it.
    expect($method->group_id)->toBe($this->cardGroup->id);
});

it('has no delete route', function (): void {
    $method = PaymentMethod::query()->where('location_id', $this->location->id)->firstOrFail();

    $this->deleteJson("/api/v1/admin/payment-methods/{$method->id}", [], $this->headers)
        ->assertStatus(405);
});

it('refuses a session without the permission', function (): void {
    $nobody = User::factory()->create(['email' => 'n@pos.test', 'password_hash' => 'pw']);
    $headers = ['Authorization' => 'Bearer '.$nobody->createToken('t')->plainTextToken];

    $this->getJson("/api/v1/admin/payment-methods?location_id={$this->location->id}", $headers)
        ->assertStatus(403);
});

it('a newly created method is immediately tenderable at the till', function (): void {
    $this->postJson('/api/v1/admin/payment-methods', [
        'location_id' => $this->location->id, 'group_id' => $this->cardGroup->id,
        'code' => 'VISA', 'name' => 'Visa',
    ], $this->headers)->assertCreated();

    $register = registerAt($this->location);
    $cashier = staffWithRole($this->location, \App\Domain\Rbac\Roles::CASHIER);

    $methods = $this->getJson('/api/v1/catalog', staffHeaders($register, $cashier))
        ->assertOk()->json('data.payment_methods');

    expect(array_column($methods, 'code'))->toContain('VISA');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/PaymentMethodCrudTest.php`
Expected: FAIL — 404, the routes do not exist.

- [ ] **Step 3: Write the three Input DTOs**

`ListPaymentMethodsInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class ListPaymentMethodsInput
{
    public function __construct(public string $locationId) {}
}
```

`CreatePaymentMethodInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class CreatePaymentMethodInput
{
    public function __construct(
        public string $locationId,
        public string $groupId,
        public string $code,
        public string $name,
        public int $sortOrder,
        public bool $isActive,
        public string $actorId,
    ) {}
}
```

`UpdatePaymentMethodInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class UpdatePaymentMethodInput
{
    /** @param array<string, mixed> $changes only name, sort_order, is_active ever reach here */
    public function __construct(
        public string $methodId,
        public array $changes,
        public string $actorId,
    ) {}
}
```

- [ ] **Step 4: Write the three actions**

`ListPaymentMethods.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;

/** Unpaginated, like every admin list in v1. */
final class ListPaymentMethods
{
    /** @return Collection<int, PaymentMethod> */
    public function execute(ListPaymentMethodsInput $in): Collection
    {
        return PaymentMethod::query()
            ->where('location_id', $in->locationId)
            ->orderBy('sort_order')->orderBy('code')
            ->get();
    }
}
```

`CreatePaymentMethod.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Domain\Audit\AuditLogger;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

final class CreatePaymentMethod
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreatePaymentMethodInput $in): PaymentMethod
    {
        return DB::transaction(function () use ($in): PaymentMethod {
            $method = PaymentMethod::create([
                'location_id' => $in->locationId,
                'group_id' => $in->groupId,
                'code' => $in->code,
                'name' => $in->name,
                'sort_order' => $in->sortOrder,
                'is_active' => $in->isActive,
            ]);

            $this->audit->record('admin.payment_method.create', $method, $in->actorId, [
                'location_id' => $in->locationId, 'group_id' => $in->groupId, 'code' => $in->code,
            ]);

            return $method;
        });
    }
}
```

`UpdatePaymentMethod.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Domain\Audit\AuditLogger;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Name, sort order and archive only. `code` and `group_id` never arrive here: the code is
 * a wire identifier and a report key, and the group carries the driver — moving a method
 * between groups would change its behaviour and re-bucket its history.
 */
final class UpdatePaymentMethod
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdatePaymentMethodInput $in): PaymentMethod
    {
        return DB::transaction(function () use ($in): PaymentMethod {
            $method = PaymentMethod::query()->lockForUpdate()->findOrFail($in->methodId);

            $method->fill($in->changes)->save();

            $this->audit->record('admin.payment_method.update', $method, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $method;
        });
    }
}
```

- [ ] **Step 5: Write the resource**

`backend/app/Http/Resources/Admin/AdminPaymentMethodResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentMethod */
final class AdminPaymentMethodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'group_id' => $this->group_id,
            'code' => $this->code,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

- [ ] **Step 6: Write the three FormRequests**

`ListPaymentMethodsRequest.php` — byte-for-byte the same as
`ListPaymentMethodGroupsRequest` from Task 9, except the class name and
`toInput()` returning `new ListPaymentMethodsInput(locationId: $this->string('location_id')->toString())`.

`CreatePaymentMethodRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\CreatePaymentMethodInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreatePaymentMethodRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'group_id' => [
                'required', 'uuid',
                // The composite FK enforces this at the database; checking here turns a
                // 500 into a field message.
                Rule::exists('payment_method_groups', 'id')
                    ->where(fn ($query) => $query->where('location_id', $this->input('location_id'))),
            ],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9_]+$/',
                // Unique per LOCATION, across every group at it.
                Rule::unique('payment_methods', 'code')
                    ->where(fn ($query) => $query->where('location_id', $this->input('location_id'))),
            ],
            'name' => ['required', 'string', 'max:200'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn () => $this->assertLocationPermitted(
            $this->string('location_id')->toString(),
            Permissions::PAYMENT_METHOD_MANAGE,
        ));
    }

    public function toInput(): CreatePaymentMethodInput
    {
        return new CreatePaymentMethodInput(
            locationId: $this->string('location_id')->toString(),
            groupId: $this->string('group_id')->toString(),
            code: $this->string('code')->toString(),
            name: $this->string('name')->toString(),
            sortOrder: $this->integer('sort_order', 0),
            isActive: $this->boolean('is_active', true),
            actorId: $this->user()->id,
        );
    }
}
```

`UpdatePaymentMethodRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\UpdatePaymentMethodInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use App\Models\PaymentMethod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePaymentMethodRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

    /**
     * `code`, `group_id` and `location_id` are absent on purpose so `safe()->only()`
     * drops them — they are immutable after create.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Scoped against the ROW's location — an update names no location itself.
        $validator->after(fn () => $this->assertLocationPermitted(
            PaymentMethod::query()->whereKey((string) $this->route('method'))->value('location_id'),
            Permissions::PAYMENT_METHOD_MANAGE,
        ));
    }

    public function toInput(): UpdatePaymentMethodInput
    {
        return new UpdatePaymentMethodInput(
            methodId: (string) $this->route('method'),
            changes: $this->safe()->only(['name', 'sort_order', 'is_active']),
            actorId: $this->user()->id,
        );
    }
}
```

- [ ] **Step 7: Write the three controllers**

Each mirrors its Task 9 counterpart exactly. `ListPaymentMethodsController`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\ListPaymentMethods;
use App\Http\Requests\Admin\PaymentMethods\ListPaymentMethodsRequest;
use App\Http\Resources\Admin\AdminPaymentMethodResource;
use Illuminate\Http\JsonResponse;

final class ListPaymentMethodsController
{
    public function __invoke(ListPaymentMethodsRequest $request, ListPaymentMethods $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminPaymentMethodResource::collection($action->execute($request->toInput()))],
        ]);
    }
}
```

`CreatePaymentMethodController`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\CreatePaymentMethod;
use App\Http\Requests\Admin\PaymentMethods\CreatePaymentMethodRequest;
use App\Http\Resources\Admin\AdminPaymentMethodResource;
use Illuminate\Http\JsonResponse;

final class CreatePaymentMethodController
{
    public function __invoke(CreatePaymentMethodRequest $request, CreatePaymentMethod $action): JsonResponse
    {
        return response()->json([
            'data' => ['payment_method' => new AdminPaymentMethodResource($action->execute($request->toInput()))],
        ], 201);
    }
}
```

`UpdatePaymentMethodController`: the same as create but with `UpdatePaymentMethod` /
`UpdatePaymentMethodRequest` and no `201`.

- [ ] **Step 8: Register the routes**

In `backend/routes/api.php`, immediately after the three group routes from Task 9:

```php
        Route::get('/payment-methods', ListPaymentMethodsController::class)
            ->name('admin.payment-methods.list');
        Route::post('/payment-methods', CreatePaymentMethodController::class)
            ->name('admin.payment-methods.create');
        Route::patch('/payment-methods/{method}', UpdatePaymentMethodController::class)
            ->name('admin.payment-methods.update');
```

Add the three controller imports.

- [ ] **Step 9: Run both admin CRUD tests**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/PaymentMethodCrudTest.php tests/Feature/Admin/PaymentMethodGroupCrudTest.php`
Expected: PASS, 16 tests.

- [ ] **Step 10: Run the arch and full suites**

Run: `cd backend && ./vendor/bin/pest tests/Arch && make test-backend`
Expected: PASS both.

- [ ] **Step 11: Commit**

```bash
git add backend/app/Actions/Admin/PaymentMethods backend/app/Http/Requests/Admin/PaymentMethods \
        backend/app/Http/Controllers/Admin/PaymentMethods backend/app/Http/Resources/Admin \
        backend/routes/api.php backend/tests/Feature/Admin/PaymentMethodCrudTest.php
git commit -m "feat(back-office): CRUD for payment methods"
```

---

## Task 11: `group_by=payment_method` on the sales report

**Files:**
- Modify: `backend/app/Actions/Admin/Reports/SalesReport.php`, `backend/app/Actions/Admin/Reports/SalesReportInput.php` (comment only)
- Modify: `backend/app/Http/Requests/Admin/Reports/SalesReportRequest.php:35`
- Test: `backend/tests/Feature/Admin/ReportsTest.php` (add cases)

**Interfaces:**
- Consumes: the ledger snapshot columns (Task 4).
- Produces: `group_by=payment_method` accepted, returning rows `{bucket, method_code, method_name, group_code, group_name, gross_cents, refunds_cents, net_cents}` with `basis: 'ledger'`. Task 13 consumes the wire shape.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Admin/ReportsTest.php`:

This file's `beforeEach` gives `$this->location`, `$this->register`, `$this->cashierA`,
`$this->headers` and `$this->today`, and opens a shift — but no paid order, so these cases
build their own:

```php
/** A closed order paid in full on one method. Own name — Pest file-scoped functions
 *  collide across files, and this file already namespaces its own helpers. */
function reportsTenderedOrder(object $t, string $methodCode, int $cents): void
{
    $order = Order::factory()->forRegister($t->register)->create([
        'opened_by' => $t->cashierA->id,
        'subtotal_cents' => $cents,
        'total_cents' => $cents,
    ]);

    app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $order->id,
        registerId: $t->register->id,
        paymentMethodCode: $methodCode,
        amountCents: $cents,
        tenderedCents: $cents,
        reference: null,
        expectedVersion: $order->version,
        actorId: $t->cashierA->id,
    ));
}

function reportsByMethod(object $t): array
{
    return $t->getJson(
        "/api/v1/admin/reports/sales?location_id={$t->location->id}"
        ."&from={$t->today}&to={$t->today}&group_by=payment_method",
        $t->headers,
    )->assertOk()->json('data');
}

it('groups sales by payment method on a ledger basis', function (): void {
    $group = \App\Models\PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card',
    ]);
    \App\Models\PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $group->id,
        'code' => 'GCASH', 'name' => 'GCash',
    ]);

    reportsTenderedOrder($this, 'CASH', 1000);
    reportsTenderedOrder($this, 'GCASH', 2500);

    $report = reportsByMethod($this);

    expect($report['basis'])->toBe('ledger');
    expect(array_column($report['rows'], 'method_code'))->toBe(['CASH', 'GCASH']);

    $cash = collect($report['rows'])->firstWhere('method_code', 'CASH');
    expect($cash['group_code'])->toBe('CASH');
    expect($cash['gross_cents'])->toBe(1000);
    expect($cash['net_cents'])->toBe($cash['gross_cents'] - $cash['refunds_cents']);

    // The e-wallet reports under its own group, not under CARD, despite sharing a driver.
    $gcash = collect($report['rows'])->firstWhere('method_code', 'GCASH');
    expect($gcash['group_code'])->toBe('EWALLET');
    expect($gcash['group_name'])->toBe('E-wallets');

    expect($report['totals']['gross_cents'])->toBe(3500);
});

it('reports a renamed method under the name it was sold as', function (): void {
    reportsTenderedOrder($this, 'CASH', 1000);

    \App\Models\PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CASH')->update(['name' => 'Cash (peso)']);

    // Grouped on the SNAPSHOT, so a rename does not retroactively rewrite last month.
    $cash = collect(reportsByMethod($this)['rows'])->firstWhere('method_code', 'CASH');
    expect($cash['method_name'])->toBe('Cash');
});

it('still rejects an unknown group_by', function (): void {
    $this->getJson(
        "/api/v1/admin/reports/sales?location_id={$this->location->id}"
        ."&from={$this->today}&to={$this->today}&group_by=tender",
        $this->headers,
    )->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/ReportsTest.php`
Expected: FAIL — 422, `group_by` is not in `day,category,user`.

- [ ] **Step 3: Accept the new grouping**

In `backend/app/Http/Requests/Admin/Reports/SalesReportRequest.php`, extend the rule:

```php
            'group_by' => ['required', 'string', 'in:day,category,user,payment_method'],
```

In `backend/app/Actions/Admin/Reports/SalesReportInput.php`, update the trailing comment:

```php
        public string $groupBy,    // 'day' | 'user' | 'category' | 'payment_method'
```

- [ ] **Step 4: Add the branch to `SalesReport`**

In `backend/app/Actions/Admin/Reports/SalesReport.php`, add the match arm:

```php
        return match ($in->groupBy) {
            'day' => $this->byDay($in),
            'user' => $this->byUser($in),
            'category' => $this->byCategory($in),
            'payment_method' => $this->byPaymentMethod($in),
        };
```

and the method (place it after `byUser`, since it is the third ledger-basis grouping):

```php
    /**
     * LEDGER-basis, like day and user: captured payments and refunds, i.e. money that
     * actually moved, bucketed by the tender it moved on.
     *
     * Grouped on the SNAPSHOT columns so a method renamed since the sale does not
     * retroactively rewrite last month's rows. The group name is joined from the live
     * tables — a method's group_id is immutable, so that join is stable, and a report
     * (unlike a receipt) is read fresh every time and may show today's group label.
     */
    private function byPaymentMethod(SalesReportInput $in): object
    {
        $gross = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.status', 'captured')
            ->where('o.location_id', $in->locationId)
            ->whereBetween('o.business_date', [$in->from, $in->to])
            ->groupBy('p.payment_method_code')
            ->selectRaw('p.payment_method_code as bucket, sum(p.amount_cents) as gross_cents')
            ->pluck('gross_cents', 'bucket');

        $refunds = DB::table('refunds')
            ->where('location_id', $in->locationId)
            ->whereBetween('business_date', [$in->from, $in->to])
            ->groupBy('payment_method_code')
            ->selectRaw('payment_method_code as bucket, sum(amount_cents) as refunds_cents')
            ->pluck('refunds_cents', 'bucket');

        $codes = collect($gross->keys())->merge($refunds->keys())->unique()->values();

        // One query maps every code in the report to its current group — never an N+1 per
        // bucket. Keyed by code, which is unique per location.
        $labels = DB::table('payment_methods as pm')
            ->join('payment_method_groups as g', 'g.id', '=', 'pm.group_id')
            ->where('pm.location_id', $in->locationId)
            ->whereIn('pm.code', $codes)
            ->get(['pm.code', 'g.code as group_code', 'g.name as group_name'])
            ->keyBy('code');

        // The name each tender was SOLD as, off the snapshot, one query for all buckets.
        // max() over the group is deliberate: a code renamed mid-window carries two
        // snapshot names and one row per code must pick one — the later name is the less
        // surprising label.
        $snapshotNames = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('o.location_id', $in->locationId)
            ->whereBetween('o.business_date', [$in->from, $in->to])
            ->groupBy('p.payment_method_code')
            ->selectRaw('p.payment_method_code as code, max(p.payment_method_name) as name')
            ->pluck('name', 'code');

        $rows = $codes->map(fn (string $code): array => [
            'bucket' => $code,
            'method_code' => $code,
            'method_name' => (string) ($snapshotNames[$code] ?? $code),
            'group_code' => $labels[$code]->group_code ?? null,
            'group_name' => $labels[$code]->group_name ?? null,
            'gross_cents' => (int) ($gross[$code] ?? 0),
            'refunds_cents' => (int) ($refunds[$code] ?? 0),
            'net_cents' => (int) ($gross[$code] ?? 0) - (int) ($refunds[$code] ?? 0),
        ])->sortBy('method_code')->values()->all();

        return (object) [
            'rows' => $rows,
            'totals' => [
                'gross_cents' => array_sum(array_column($rows, 'gross_cents')),
                'refunds_cents' => array_sum(array_column($rows, 'refunds_cents')),
                'net_cents' => array_sum(array_column($rows, 'net_cents')),
            ],
            'basis' => 'ledger',
        ];
    }
```

`bucket` is duplicated as `method_code` on purpose: every other grouping returns a
`bucket` key and the back office's shared table reads it, while `method_code` is the name
the payment-method view actually wants to show.

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Admin/ReportsTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full backend suite**

Run: `make test-backend`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Actions/Admin/Reports backend/app/Http/Requests/Admin/Reports \
        backend/tests/Feature/Admin/ReportsTest.php
git commit -m "feat(reports): sales report grouped by payment method"
```

---

## Task 12: Seed PH-realistic tenders

**Files:**
- Modify: `backend/database/seeders/GrocerySeeder.php`, `RestaurantSeeder.php`, `CafeSeeder.php` (or their shared `CatalogSeeder` base, if the location-creation step lives there)
- Test: `backend/tests/Feature/Seed/SeedPaymentMethodsTest.php`

**Interfaces:**
- Consumes: `PaymentMethodProvisioner` (Task 1) and the two models.
- Produces: every seeded location carries `CASH` → Cash; `CARD` → Visa, Mastercard; `EWALLET` → GCash, Maya.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Seed/SeedPaymentMethodsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;

it('gives every seeded location the PH tender set', function (): void {
    config(['pos.seed_catalogs' => 'restaurant']);
    $this->seed(\Database\Seeders\DatabaseSeeder::class);

    $location = Location::query()->where('code', 'RST')->firstOrFail();

    $groups = PaymentMethodGroup::query()->where('location_id', $location->id)
        ->orderBy('sort_order')->pluck('code')->all();
    expect($groups)->toBe(['CASH', 'CARD', 'EWALLET']);

    $methods = PaymentMethod::query()->where('location_id', $location->id)
        ->orderBy('code')->pluck('code')->all();
    expect($methods)->toBe(['CASH', 'GCASH', 'MASTERCARD', 'MAYA', 'VISA']);

    // A multi-group set out of the box, so the till renders real grouping rather than a
    // two-button special case that hides grouping bugs.
    expect(PaymentMethodGroup::query()->where('location_id', $location->id)
        ->where('code', 'EWALLET')->value('driver'))->toBe('external_card');
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Seed/SeedPaymentMethodsTest.php`
Expected: FAIL — only `CASH` and `CARD` groups exist (the provisioner's defaults).

- [ ] **Step 3: Find where the seeders create their location**

Run: `grep -n "Location::create\|Location::factory\|'code' =>" backend/database/seeders/*.php`

Each of the three catalog seeders creates one location (`GRC`, `RST`, `CAF`). Whichever
shared method they use, the tender seeding goes immediately after the location exists.

- [ ] **Step 4: Add a `seedPaymentMethods` step**

Add this private method to whichever class owns location creation (`CatalogSeeder` if it
is the shared base, otherwise all three seeders — prefer the shared base), and call it
right after the location row is created:

```php
    /**
     * PH-realistic tenders, written in full.
     *
     * This does NOT call PaymentMethodProvisioner: the seeder builds its location with
     * Location::factory(), not CreateLocation, so no defaults exist here to reconcile
     * with — and writing the intended set directly means nothing has to be deleted, which
     * matters because this system archives rather than deletes everywhere else.
     */
    private function seedPaymentMethods(Location $location): void
    {
        $tenders = [
            ['CASH', 'Cash', 'cash', 0, [['CASH', 'Cash', 0]]],
            ['CARD', 'Cards', 'external_card', 1, [['VISA', 'Visa', 0], ['MASTERCARD', 'Mastercard', 1]]],
            ['EWALLET', 'E-wallets', 'external_card', 2, [['GCASH', 'GCash', 0], ['MAYA', 'Maya', 1]]],
        ];

        foreach ($tenders as [$groupCode, $groupName, $driver, $groupSort, $methods]) {
            $group = PaymentMethodGroup::create([
                'location_id' => $location->id,
                'code' => $groupCode,
                'name' => $groupName,
                'driver' => $driver,
                'sort_order' => $groupSort,
                'is_active' => true,
            ]);

            foreach ($methods as [$code, $name, $sort]) {
                PaymentMethod::create([
                    'location_id' => $location->id,
                    'group_id' => $group->id,
                    'code' => $code,
                    'name' => $name,
                    'sort_order' => $sort,
                    'is_active' => true,
                ]);
            }
        }
    }
```

Add `use App\Models\PaymentMethod;` and `use App\Models\PaymentMethodGroup;` to that
file's imports.

**Verify the premise before writing this.** Run
`grep -n "Location::create\|Location::factory\|CreateLocation" backend/database/seeders/*.php`.
If a seeder ever routes location creation through `CreateLocation`, the provisioner's
`CASH`/`CARD` defaults *would* already exist and this method would collide on the unique
`(location_id, code)` index. Today `CatalogSeeder.php:55` uses `Location::factory()`, so
there is no collision — if that has changed, call
`app(PaymentMethodProvisioner::class)->provisionForLocation($location->id)` first and skip
the `CASH` and `CARD` group creations here rather than deleting anything.

- [ ] **Step 5: Run the seed test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Seed/SeedPaymentMethodsTest.php`
Expected: PASS.

- [ ] **Step 6: Seed for real and eyeball it**

Run: `make seed`
Expected: completes and prints the usual PINs/tokens table. Then:

```bash
docker compose -f compose.dev.yml exec --user pos api \
  php artisan tinker --execute="dump(\App\Models\PaymentMethod::query()->pluck('code')->all());"
```

Expected: the five codes per seeded location.

- [ ] **Step 7: Run the full backend suite**

Run: `make test-backend`
Expected: PASS. `tests/Unit/SeedDataTest.php` pins the committed JSON catalogs only — the
tender set is a seeder class, not JSON, so it should need no change.

- [ ] **Step 8: Commit**

```bash
git add backend/database/seeders backend/tests/Feature/Seed/SeedPaymentMethodsTest.php
git commit -m "feat(seed): PH tender set — cash, Visa/Mastercard, GCash/Maya"
```

---

## Task 13: Back-office API client and section registry

**Files:**
- Modify: `frontend/back-office/src/lib/api.ts`
- Modify: `frontend/back-office/src/admin/navigation.ts`
- Test: `frontend/back-office/src/admin/navigation.test.ts` (add cases)

**Interfaces:**
- Consumes: `GET|POST /admin/payment-method-groups`, `PATCH /admin/payment-method-groups/{id}`, and the three `payment-methods` routes (Tasks 9–10).
- Produces: exported types `PaymentMethodGroup`, `PaymentMethod`, `PaymentDriverCode`; `api.paymentMethodGroups.{list,create,update}` and `api.paymentMethods.{list,create,update}`; `SECTION_DEFS['payment-methods']`. Task 14 consumes all of them.

- [ ] **Step 1: Write the failing navigation test**

Append to `frontend/back-office/src/admin/navigation.test.ts`:

```ts
it('resolves /payment-methods for a holder and falls back to today without it', () => {
  expect(resolveSection('/payment-methods', ['payment_method.manage'])).toBe('payment-methods')
  expect(resolveSection('/payment-methods', ['catalog.manage'])).toBe('today')
})

it('maps the payment-methods section back to its path', () => {
  expect(pathForSection('payment-methods')).toBe('/payment-methods')
})
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd frontend/back-office && npm test -- navigation`
Expected: FAIL — resolves to `'today'` for a holder.

- [ ] **Step 3: Add the registry entry**

In `frontend/back-office/src/admin/navigation.ts`, add to `SECTION_DEFS` between `locations` and `settings` (the object's key order is the sidebar's Operations order):

```ts
  'payment-methods': { path: '/payment-methods', permissions: ['payment_method.manage'] },
```

- [ ] **Step 4: Run the navigation test to verify it passes**

Run: `cd frontend/back-office && npm test -- navigation`
Expected: PASS.

- [ ] **Step 5: Add the API types and clients**

In `frontend/back-office/src/lib/api.ts`, add near the other admin entity types:

```ts
// ---------------------------------------------------------------------------
// Payment methods — verified against AdminPaymentMethodGroupResource.php and
// AdminPaymentMethodResource.php. Per-LOCATION rows: both lists take location_id, and
// both codes are unique per location (the same code at another store is legal).
// ---------------------------------------------------------------------------

/** The code seam. Carried by the GROUP, which is why a method has no driver of its own. */
export type PaymentDriverCode = 'cash' | 'external_card'

export type PaymentMethod = {
  id: string
  location_id: string
  group_id: string
  /** Immutable after create — a wire identifier and a report key. */
  code: string
  name: string
  sort_order: number
  is_active: boolean
}

export type PaymentMethodGroup = {
  id: string
  location_id: string
  /** Immutable after create, like `driver`. */
  code: string
  name: string
  driver: PaymentDriverCode
  sort_order: number
  is_active: boolean
  /** Nested by the list endpoint's eager load, so the section renders in one call. */
  methods?: PaymentMethod[]
}
```

and to the `api` object, after `locations`/`registers`:

```ts
  // Both lists are location-scoped: holding payment_method.manage somewhere gets you
  // into the section, not into every store's tenders (docs/05-rbac.md).
  paymentMethodGroups: {
    list: (locationId: string): Promise<PaymentMethodGroup[]> =>
      request<{ items: PaymentMethodGroup[] }>(
        `/admin/payment-method-groups${qs({ location_id: locationId })}`,
      ).then((r) => r.items),
    create: (body: Record<string, unknown>): Promise<PaymentMethodGroup> =>
      post<{ payment_method_group: PaymentMethodGroup }>('/admin/payment-method-groups', body).then(
        (r) => r.payment_method_group,
      ),
    // name / sort_order / is_active only — code and driver are immutable server-side and
    // are silently dropped if sent, so the editor renders them read-only on edit.
    update: (id: string, body: Record<string, unknown>): Promise<PaymentMethodGroup> =>
      patch<{ payment_method_group: PaymentMethodGroup }>(
        `/admin/payment-method-groups/${id}`,
        body,
      ).then((r) => r.payment_method_group),
  },
  paymentMethods: {
    list: (locationId: string): Promise<PaymentMethod[]> =>
      request<{ items: PaymentMethod[] }>(`/admin/payment-methods${qs({ location_id: locationId })}`).then(
        (r) => r.items,
      ),
    create: (body: Record<string, unknown>): Promise<PaymentMethod> =>
      post<{ payment_method: PaymentMethod }>('/admin/payment-methods', body).then((r) => r.payment_method),
    // name / sort_order / is_active only — code and group_id are immutable server-side.
    update: (id: string, body: Record<string, unknown>): Promise<PaymentMethod> =>
      patch<{ payment_method: PaymentMethod }>(`/admin/payment-methods/${id}`, body).then(
        (r) => r.payment_method,
      ),
  },
```

These are hand-written rather than `catalogEntity(...)` calls because `catalogEntity`'s
`list` takes no arguments and these lists require `location_id`.

- [ ] **Step 6: Add `payment_method` to the sales report params type**

Still in `frontend/back-office/src/lib/api.ts`, widen the `group_by` union on the sales
report params type (it currently reads `'day' | 'category' | 'user'`):

```ts
  group_by: 'day' | 'category' | 'user' | 'payment_method'
```

and add the four optional row fields to the shared report-row type, alongside `bucket`:

```ts
  // Present only for group_by=payment_method (ledger basis).
  method_code?: string
  method_name?: string
  group_code?: string | null
  group_name?: string | null
```

- [ ] **Step 7: Typecheck**

Run: `cd frontend/back-office && npm run typecheck`
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add frontend/back-office/src/lib/api.ts frontend/back-office/src/admin/navigation.ts \
        frontend/back-office/src/admin/navigation.test.ts
git commit -m "feat(back-office): payment-methods section registry entry and API client"
```

---

## Task 14: Back-office Payment methods section

**Files:**
- Create: `frontend/back-office/src/admin/payments/PaymentMethodsSection.tsx`, `GroupEditor.tsx`, `MethodEditor.tsx`
- Create: `frontend/back-office/src/admin/payments/PaymentMethodsSection.test.tsx`
- Modify: `frontend/back-office/src/admin/Shell.tsx`
- Modify: `frontend/back-office/src/admin/Shell.test.tsx` (add a nav-visibility case)

**Interfaces:**
- Consumes: `api.paymentMethodGroups`, `api.paymentMethods`, `PaymentMethodGroup`, `PaymentMethod`, `PaymentDriverCode` (Task 13); `EntityTable`, `ConfirmDialog`, `FieldRow`, `Card`, `Button`, `Input`, `Checkbox`, `StatusPill` (existing shared UI).
- Produces: `<PaymentMethodsSection location onUnauthorized />`, rendered by `Shell` for `section === 'payment-methods'`.

- [ ] **Step 1: Write the failing test**

Create `frontend/back-office/src/admin/payments/PaymentMethodsSection.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PaymentMethodsSection } from './PaymentMethodsSection'
import { api, type Location } from '../../lib/api'

const location = { id: 'loc-1', name: 'Grocery', code: 'GRC' } as Location

const groups = [
  {
    id: 'g-cash', location_id: 'loc-1', code: 'CASH', name: 'Cash',
    driver: 'cash' as const, sort_order: 0, is_active: true,
    methods: [
      { id: 'm-cash', location_id: 'loc-1', group_id: 'g-cash', code: 'CASH', name: 'Cash', sort_order: 0, is_active: true },
    ],
  },
  {
    id: 'g-ewallet', location_id: 'loc-1', code: 'EWALLET', name: 'E-wallets',
    driver: 'external_card' as const, sort_order: 1, is_active: true,
    methods: [
      { id: 'm-gcash', location_id: 'loc-1', group_id: 'g-ewallet', code: 'GCASH', name: 'GCash', sort_order: 0, is_active: true },
      { id: 'm-maya', location_id: 'loc-1', group_id: 'g-ewallet', code: 'MAYA', name: 'Maya', sort_order: 1, is_active: false },
    ],
  },
]

function renderSection() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <PaymentMethodsSection location={location} onUnauthorized={() => {}} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.spyOn(api.paymentMethodGroups, 'list').mockResolvedValue(groups)
})

describe('PaymentMethodsSection', () => {
  it('lists each group with its driver and its methods', async () => {
    renderSection()

    expect(await screen.findByText('Cash')).toBeInTheDocument()
    expect(screen.getByText('E-wallets')).toBeInTheDocument()
    expect(screen.getByText('GCash')).toBeInTheDocument()
    // The driver is shown because it is the behaviour every method under the group
    // inherits, and it cannot be changed later.
    expect(screen.getAllByText(/external card/i).length).toBeGreaterThan(0)
  })

  it('marks an archived method rather than hiding it', async () => {
    renderSection()

    await screen.findByText('Maya')
    expect(screen.getAllByText('ARCHIVED').length).toBeGreaterThan(0)
  })

  it('scopes its query to the selected location', async () => {
    renderSection()

    await waitFor(() => expect(api.paymentMethodGroups.list).toHaveBeenCalledWith('loc-1'))
  })

  it('opens the group editor with code and driver read-only', async () => {
    renderSection()

    await userEvent.click((await screen.findAllByRole('button', { name: /edit/i }))[0])

    // Immutable fields render read-only rather than absent, so an admin can see the code
    // they cannot change.
    const code = screen.getByLabelText(/group code/i)
    expect(code).toHaveAttribute('readonly')
  })

  it('offers a new group with an editable code', async () => {
    renderSection()

    await userEvent.click(await screen.findByRole('button', { name: /new group/i }))

    expect(screen.getByLabelText(/group code/i)).not.toHaveAttribute('readonly')
  })
})
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd frontend/back-office && npm test -- PaymentMethodsSection`
Expected: FAIL — cannot resolve `./PaymentMethodsSection`.

- [ ] **Step 3: Write the group editor**

Create `frontend/back-office/src/admin/payments/GroupEditor.tsx`:

```tsx
'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location, type PaymentDriverCode, type PaymentMethodGroup } from '../../lib/api'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'

const CODE_RE = /^[A-Z0-9_]+$/

/**
 * A group is the behavioural bucket: it names ONE driver, and its methods are variants
 * that behave identically. `code` and `driver` are immutable after create — changing
 * either would change how every method under it behaves and retroactively re-bucket
 * history — so on edit they render read-only rather than disappearing.
 */
export function GroupEditor({
  group,
  location,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  group: PaymentMethodGroup | null
  location: Location
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [code, setCode] = useState(group?.code ?? '')
  const [name, setName] = useState(group?.name ?? '')
  const [driver, setDriver] = useState<PaymentDriverCode>(group?.driver ?? 'cash')
  const [sortOrder, setSortOrder] = useState(String(group?.sort_order ?? 0))
  const [isActive, setIsActive] = useState(group?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)
  const [pendingArchive, setPendingArchive] = useState<Record<string, unknown> | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      group ? api.paymentMethodGroups.update(group.id, body) : api.paymentMethodGroups.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'payment-method-groups', location.id] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the group.')
    },
  })

  const normalizedCode = code.trim().toUpperCase()
  const codeInvalid = !group && !CODE_RE.test(normalizedCode)

  const submit = (event: FormEvent) => {
    event.preventDefault()
    setError(null)
    if (codeInvalid) return setError('A group code is letters, digits and underscores, like EWALLET.')
    if (name.trim() === '') return setError('Give the group a name.')

    // Only what the server accepts: an update never sends code or driver.
    const body: Record<string, unknown> = group
      ? { name: name.trim(), sort_order: Number(sortOrder) || 0, is_active: isActive }
      : {
          location_id: location.id,
          code: normalizedCode,
          name: name.trim(),
          driver,
          sort_order: Number(sortOrder) || 0,
          is_active: isActive,
        }

    // Archiving a group hides every method under it — worth confirming out loud.
    if (group?.is_active && !isActive) return setPendingArchive(body)
    save.mutate(body)
  }

  return (
    <Card>
      <CardTitle>{group ? `Group ${group.code}` : 'New group'}</CardTitle>
      <form onSubmit={submit} className="flex flex-col gap-md pt-md">
        {/* FieldRow wraps its children in the <label> itself — no htmlFor/id needed, and
            getByLabelText still finds the input through that implicit association. */}
        <FieldRow label="Group code">
          <Input
            value={group ? group.code : code}
            onChange={(e) => setCode(e.target.value)}
            readOnly={group !== null}
            placeholder="EWALLET"
          />
        </FieldRow>

        <FieldRow label="Name">
          <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="E-wallets" />
        </FieldRow>

        <FieldRow label="Driver">
          {group ? (
            <Input value={driverLabel(group.driver)} readOnly />
          ) : (
            <select
              className="h-[40px] w-full border border-border bg-field px-sm"
              value={driver}
              onChange={(e) => setDriver(e.target.value as PaymentDriverCode)}
            >
              <option value="cash">Cash — opens the drawer, computes change, refundable</option>
              <option value="external_card">External card — recorded only, not refundable here</option>
            </select>
          )}
        </FieldRow>

        <FieldRow label="Sort order">
          <Input value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} inputMode="numeric" />
        </FieldRow>

        <label className="flex items-center gap-sm">
          <Checkbox checked={isActive} onCheckedChange={(v) => setIsActive(v === true)} />
          <span className="type-body-sm">Active</span>
        </label>

        {error && <p className="type-body-sm text-error">{error}</p>}

        <div className="flex gap-sm">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
          <Button type="button" variant="tertiary" onClick={onCancel}>
            Cancel
          </Button>
        </div>
      </form>

      {/* ConfirmDialog takes a single `message` (it renders as the DialogTitle), and
          cancelling is onOpenChange(false) — there is no separate onCancel. */}
      <ConfirmDialog
        open={pendingArchive !== null}
        onOpenChange={(open) => {
          if (!open) setPendingArchive(null)
        }}
        message="Archive this group? Every method in it stops appearing at the till. Their rows are kept, so reactivating the group brings them all back."
        confirmLabel="Archive"
        destructive
        onConfirm={() => {
          const body = pendingArchive
          setPendingArchive(null)
          if (body) save.mutate(body)
        }}
      />
    </Card>
  )
}

export function driverLabel(driver: PaymentDriverCode): string {
  return driver === 'cash' ? 'Cash' : 'External card'
}
```

Verified against `frontend/back-office/src/components/`: `FieldRow` is
`{ label, error, children }`, `ConfirmDialog` is
`{ open, onOpenChange, message, confirmLabel, cancelLabel?, destructive?, onConfirm }`.
`Button` variants are `primary | secondary | tertiary | ghost | danger`.

- [ ] **Step 4: Write the method editor**

Create `frontend/back-office/src/admin/payments/MethodEditor.tsx`:

```tsx
'use client'

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, api, type Location, type PaymentMethod, type PaymentMethodGroup } from '../../lib/api'
import { FieldRow } from '../../components/FieldRow'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { Checkbox } from '../../components/ui/checkbox'
import { Input } from '../../components/ui/input'
import { driverLabel } from './GroupEditor'

const CODE_RE = /^[A-Z0-9_]+$/

/**
 * A method is a NAME for behaviour its group already defines. `code` and the group are
 * immutable after create: the code is a wire identifier and a report key, and moving a
 * method between groups would change its driver and re-bucket its history.
 */
export function MethodEditor({
  method,
  group,
  location,
  onDone,
  onCancel,
  onUnauthorized,
}: {
  method: PaymentMethod | null
  group: PaymentMethodGroup
  location: Location
  onDone: () => void
  onCancel: () => void
  onUnauthorized: () => void
}) {
  const queryClient = useQueryClient()
  const [code, setCode] = useState(method?.code ?? '')
  const [name, setName] = useState(method?.name ?? '')
  const [sortOrder, setSortOrder] = useState(String(method?.sort_order ?? 0))
  const [isActive, setIsActive] = useState(method?.is_active ?? true)
  const [error, setError] = useState<string | null>(null)

  const save = useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      method ? api.paymentMethods.update(method.id, body) : api.paymentMethods.create(body),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'payment-method-groups', location.id] })
      onDone()
    },
    onError: (err) => {
      if (err instanceof ApiError && err.status === 401) return onUnauthorized()
      setError(err instanceof ApiError ? err.message : 'Could not save the method.')
    },
  })

  const normalizedCode = code.trim().toUpperCase()
  const codeInvalid = !method && !CODE_RE.test(normalizedCode)

  const submit = (event: FormEvent) => {
    event.preventDefault()
    setError(null)
    if (codeInvalid) return setError('A method code is letters, digits and underscores, like GCASH.')
    if (name.trim() === '') return setError('Give the method a name.')

    save.mutate(
      method
        ? { name: name.trim(), sort_order: Number(sortOrder) || 0, is_active: isActive }
        : {
            location_id: location.id,
            group_id: group.id,
            code: normalizedCode,
            name: name.trim(),
            sort_order: Number(sortOrder) || 0,
            is_active: isActive,
          },
    )
  }

  return (
    <Card>
      <CardTitle>{method ? `Method ${method.code}` : `New method in ${group.name}`}</CardTitle>
      <p className="type-body-sm text-ink-muted">
        Behaves as {driverLabel(group.driver)}, because that is what the {group.code} group is.
      </p>
      <form onSubmit={submit} className="flex flex-col gap-md pt-md">
        {/* FieldRow supplies the <label> wrapper — no htmlFor/id. */}
        <FieldRow label="Method code">
          <Input
            value={method ? method.code : code}
            onChange={(e) => setCode(e.target.value)}
            readOnly={method !== null}
            placeholder="GCASH"
          />
        </FieldRow>

        <FieldRow label="Name">
          <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="GCash" />
        </FieldRow>

        <FieldRow label="Sort order">
          <Input value={sortOrder} onChange={(e) => setSortOrder(e.target.value)} inputMode="numeric" />
        </FieldRow>

        <label className="flex items-center gap-sm">
          <Checkbox checked={isActive} onCheckedChange={(v) => setIsActive(v === true)} />
          <span className="type-body-sm">Active</span>
        </label>

        {error && <p className="type-body-sm text-error">{error}</p>}

        <div className="flex gap-sm">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save'}
          </Button>
          <Button type="button" variant="tertiary" onClick={onCancel}>
            Cancel
          </Button>
        </div>
      </form>
    </Card>
  )
}
```

- [ ] **Step 5: Write the section**

Create `frontend/back-office/src/admin/payments/PaymentMethodsSection.tsx`:

```tsx
'use client'

import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { ApiError, api, type Location, type PaymentMethod, type PaymentMethodGroup } from '../../lib/api'
import { EmptyState } from '../../components/EmptyState'
import { StatusPill } from '../../components/StatusPill'
import { Button } from '../../components/ui/button'
import { Card, CardTitle } from '../../components/ui/card'
import { GroupEditor, driverLabel } from './GroupEditor'
import { MethodEditor } from './MethodEditor'

type Editing =
  | { kind: 'group'; group: PaymentMethodGroup | null }
  | { kind: 'method'; group: PaymentMethodGroup; method: PaymentMethod | null }
  | null

/**
 * The location's tender taxonomy: groups (each naming one driver) with their methods
 * nested beneath. Scoped by the sidebar location switcher — payment methods are
 * per-location data, and both codes are unique per location.
 *
 * Archive, never delete: an archived row stays visible and dimmed with an ARCHIVED pill,
 * the same mechanics the catalog uses.
 */
export function PaymentMethodsSection({
  location,
  onUnauthorized,
}: {
  location: Location | null
  onUnauthorized: () => void
}) {
  const [editing, setEditing] = useState<Editing>(null)

  const groups = useQuery({
    queryKey: ['admin', 'payment-method-groups', location?.id ?? null],
    queryFn: () => api.paymentMethodGroups.list(location!.id),
    enabled: location !== null,
  })

  useEffect(() => {
    if (groups.error instanceof ApiError && groups.error.status === 401) onUnauthorized()
  }, [groups.error, onUnauthorized])

  if (location === null) return <p className="type-body-sm text-ink-muted">Pick a location first.</p>
  if (groups.isLoading) return <p className="type-body-sm text-ink-muted">Loading…</p>
  if (groups.isError) return <p className="type-body-sm text-error">Could not load payment methods.</p>

  if (editing?.kind === 'group') {
    return (
      <GroupEditor
        group={editing.group}
        location={location}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  if (editing?.kind === 'method') {
    return (
      <MethodEditor
        method={editing.method}
        group={editing.group}
        location={location}
        onDone={() => setEditing(null)}
        onCancel={() => setEditing(null)}
        onUnauthorized={onUnauthorized}
      />
    )
  }

  const rows = groups.data ?? []

  return (
    <div className="flex flex-col gap-lg">
      <div className="flex items-center justify-between">
        <CardTitle>Payment methods — {location.name}</CardTitle>
        <Button onClick={() => setEditing({ kind: 'group', group: null })}>New group</Button>
      </div>

      {rows.length === 0 && (
        <EmptyState
          title="No payment methods yet"
          description="This location cannot take payment until it has at least one method. Start with a group."
        />
      )}

      {rows.map((group) => (
        <Card key={group.id} className={group.is_active ? undefined : 'opacity-60'}>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-sm">
              <CardTitle>{group.name}</CardTitle>
              <span className="type-body-sm text-ink-muted">
                {group.code} · {driverLabel(group.driver)}
              </span>
              {!group.is_active && <StatusPill tone="neutral">ARCHIVED</StatusPill>}
            </div>
            <div className="flex gap-sm">
              <Button variant="tertiary" onClick={() => setEditing({ kind: 'group', group })}>
                Edit
              </Button>
              <Button variant="tertiary" onClick={() => setEditing({ kind: 'method', group, method: null })}>
                New method
              </Button>
            </div>
          </div>

          <ul className="flex flex-col gap-xs pt-md">
            {(group.methods ?? []).map((method) => (
              <li key={method.id} className="flex items-center justify-between border-t border-border pt-xs">
                {/* StatusPill takes only `tone` and children — spacing goes on a wrapper. */}
                <span className={method.is_active ? undefined : 'opacity-60'}>
                  {method.name} <span className="type-body-sm text-ink-muted">{method.code}</span>
                  {!method.is_active && (
                    <span className="ml-sm">
                      <StatusPill tone="neutral">ARCHIVED</StatusPill>
                    </span>
                  )}
                </span>
                <Button variant="ghost" onClick={() => setEditing({ kind: 'method', group, method })}>
                  Edit
                </Button>
              </li>
            ))}
            {(group.methods ?? []).length === 0 && (
              <li className="type-body-sm text-ink-muted">No methods in this group yet.</li>
            )}
          </ul>
        </Card>
      ))}
    </div>
  )
}
```

Verified signatures: `EmptyState` is `{ title, description? }`; `StatusPill` is
`{ tone, children }` with tones `success | warning | error | info | neutral` and **no**
`className`; `Button` variants are `primary | secondary | tertiary | ghost | danger`.

Do **not** add or edit a shared component — `carbon.css`, `lib/utils.ts`, all of
`components/ui/*`, `StatusPill`, `EmptyState` and `ConfirmDialog` are byte-identical with
`frontend/web`, so touching one here would silently desync the pair.

- [ ] **Step 6: Wire it into `Shell`**

In `frontend/back-office/src/admin/Shell.tsx`:

Add the import next to the other section imports:

```tsx
import { PaymentMethodsSection } from './payments/PaymentMethodsSection'
```

Add the permission read next to the others:

```tsx
  const canManagePaymentMethods = holdsSection('payment-methods', sections)
```

Add the nav item to the Operations `items` array, after Locations and before Settings:

```tsx
        ...(canManagePaymentMethods
          ? [{ key: 'payment-methods', label: 'Payment methods', href: pathForSection('payment-methods') }]
          : []),
```

Add the render case after the `locations` case:

```tsx
        {section === 'payment-methods' && (
          <PaymentMethodsSection location={location} onUnauthorized={onUnauthorized} />
        )}
```

- [ ] **Step 7: Add the Shell nav test**

Append to `frontend/back-office/src/admin/Shell.test.tsx`, following the file's existing
render helper and its nav-visibility cases:

```tsx
it('shows Payment methods only to a holder of payment_method.manage', () => {
  renderShell({ sections: ['payment_method.manage'] })
  expect(screen.getByRole('button', { name: /payment methods/i })).toBeInTheDocument()
})

it('hides Payment methods without the permission', () => {
  renderShell({ sections: ['catalog.manage'] })
  expect(screen.queryByRole('button', { name: /payment methods/i })).not.toBeInTheDocument()
})
```

Nav items are **buttons** inside a `navigation` landmark named "Sections" (that is how
`docs/user-manual/capture_screenshots.mjs` drives them), not links. Match `renderShell`'s
real signature in that file.

- [ ] **Step 8: Run the section and Shell tests**

Run: `cd frontend/back-office && npm test -- PaymentMethodsSection Shell navigation`
Expected: PASS.

- [ ] **Step 9: Typecheck, build, and run the whole suite**

Run: `cd frontend/back-office && npm run typecheck && npm test && npm run build`
Expected: all clean.

- [ ] **Step 10: Look at it**

Run: `make dev` (if not already up), then open <http://127.0.0.1:5175>, sign in as
`admin@pos.test` / `password`, and click **Payment methods**. Expected: three groups for
the seeded location (Cash, Cards, E-wallets) with their methods nested, and switching
location in the sidebar reloads the list.

- [ ] **Step 11: Commit**

```bash
git add frontend/back-office/src/admin/payments frontend/back-office/src/admin/Shell.tsx \
        frontend/back-office/src/admin/Shell.test.tsx
git commit -m "feat(back-office): Payment methods section"
```

---

## Task 15: Register API client on method codes

**Files:**
- Modify: `frontend/web/src/lib/api.ts`
- Test: `frontend/web/src/lib/api.test.ts` (update the payment/refund cases)

**Interfaces:**
- Consumes: the `payment_methods` catalog key (Task 7), the new payment/refund request bodies (Tasks 4–5), the Z-report's four maps (Task 8).
- Produces: exported type `CatalogPaymentMethod`; `Catalog.payment_methods`; `api.takePayment(order, amountCents, paymentMethodCode, idempotencyKey, options)`; `api.refund(originalOrderId, paymentMethodCode, reason, lines, idempotencyKey)`; `PaymentOutcome.payment.payment_method_code|payment_method_name`; `ZReport.sales_by_method|sales_by_group|refunds_by_method|refunds_by_group`. Task 16 consumes all of it.

- [ ] **Step 1: Write the failing test**

Append to `frontend/web/src/lib/api.test.ts`, matching the file's existing fetch-mock helper:

```ts
it('posts a payment by method code, not by driver', async () => {
  const order = { id: 'o-1', version: 3, total_cents: 5000, paid_cents: 0 } as never

  mockJson({ data: { payment: { id: 'p-1' }, order: {} } })
  await api.takePayment(order, 5000, 'GCASH', 'key-1', { reference: 'ref' })

  const body = JSON.parse(lastRequest().body as string)
  expect(body.payment_method_code).toBe('GCASH')
  expect(body).not.toHaveProperty('driver')
})

it('posts a refund by method code', async () => {
  mockJson({ data: { refund: { id: 'r-1' } } })
  await api.refund('o-1', 'CASH', 'Faulty', [{ original_order_line_id: 'l-1', qty: '1', restock: true }], 'key-2')

  const body = JSON.parse(lastRequest().body as string)
  expect(body.payment_method_code).toBe('CASH')
  expect(body).not.toHaveProperty('driver')
})
```

Use whatever this file already calls its fetch-mock and last-request helpers — do not
invent new ones.

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd frontend/web && npm test -- api`
Expected: FAIL — the body carries `driver`.

- [ ] **Step 3: Add the catalog type**

In `frontend/web/src/lib/api.ts`, next to the other catalog types:

```ts
/**
 * A tender button. Verified against GetCatalog.php / CatalogResource.php: active methods
 * in ACTIVE groups only, already ordered (group sort, group code, method sort, method
 * code) — the till renders this list as given and never re-sorts it.
 *
 * `driver` is the GROUP's, and it decides the input the till shows: `cash` takes an
 * amount handed over and gets a server-computed change; `external_card` takes an
 * optional reference and tenders nothing.
 */
export type CatalogPaymentMethod = {
  id: string
  code: string
  name: string
  group_code: string
  group_name: string
  driver: 'cash' | 'external_card'
  sort_order: number
}
```

and add to the `Catalog` type, before `currency`:

```ts
  payment_methods: CatalogPaymentMethod[]
```

- [ ] **Step 4: Widen `PaymentOutcome` and `ZReport`, drop the `Driver` alias**

Replace the `PaymentOutcome` payment shape:

```ts
export type PaymentOutcome = {
  payment: {
    id: string
    driver: string
    payment_method_code: string
    payment_method_name: string
    status: string
    amount_cents: number
    tendered_cents: number | null
    change_cents: number | null
  }
  order: Order
}
```

In the `ZReport` type, replace `sales_by_driver` / `refunds_by_driver` with the four maps
and update the comment above it:

```ts
// Verified against ZReportResource.php / GetZReport.php: all four are `code => cents`
// maps (only codes with activity are present). Methods are keyed by the SNAPSHOT code, so
// a renamed method still reports under the code it was sold as; groups are keyed by the
// group's code — CARD and EWALLET stay apart even though both drive external_card.
  sales_by_method: Record<string, number>
  sales_by_group: Record<string, number>
  refunds_by_method: Record<string, number>
  refunds_by_group: Record<string, number>
```

Delete the now-unused `type Driver = 'cash' | 'external_card'` alias if nothing else
references it (`grep -rn "Driver" src/`), and widen `Refund`'s `driver: string` to also
carry `payment_method_code: string` and `payment_method_name: string`.

- [ ] **Step 5: Change the two request signatures**

Replace `takePayment`:

```ts
  // idempotencyKey is minted once by the caller (when the tender phase is entered) and
  // reused across retries — see closeShift's note. The METHOD CODE is what the till
  // sends; the server resolves it to a driver via the method's group, so a client can no
  // longer name a tender behaviour at all (422 payment_method_unknown / _inactive).
  // tenderedCents/reference stay options because they're driver-specific: a cash-driver
  // method tenders and gets a computed change_cents; an external_card one supplies a
  // reference instead and tenders nothing (the server treats tendered_cents as absent
  // when null, not literally zero).
  takePayment: (
    order: Order,
    amountCents: number,
    paymentMethodCode: string,
    idempotencyKey: string,
    options?: { tenderedCents?: number; reference?: string },
  ) =>
    post<PaymentOutcome>(
      `/orders/${order.id}/payments`,
      {
        payment_method_code: paymentMethodCode,
        amount_cents: amountCents,
        tendered_cents: options?.tenderedCents ?? null,
        reference: options?.reference ?? null,
      },
      { 'If-Match': String(order.version), 'Idempotency-Key': idempotencyKey },
    ),
```

Replace `refund`:

```ts
  // original_order_id + lines derive the amount server-side from the original lines'
  // frozen price/tax snapshot (RefundOrder.php) — the client only chooses qty/restock.
  // A method whose driver cannot return money is refused with
  // 422 refund_method_not_refundable, sourced from Capabilities::refundable.
  refund: (
    originalOrderId: string,
    paymentMethodCode: string,
    reason: string,
    lines: Array<{ original_order_line_id: string; qty: string; restock: boolean }>,
    idempotencyKey: string,
  ) =>
    post<{ refund: Refund }>(
      '/refunds',
      { original_order_id: originalOrderId, payment_method_code: paymentMethodCode, reason, lines },
      { 'Idempotency-Key': idempotencyKey },
    ).then((r) => r.refund),
```

- [ ] **Step 6: Run the api test**

Run: `cd frontend/web && npm test -- api`
Expected: PASS.

- [ ] **Step 7: Typecheck to find every caller**

Run: `cd frontend/web && npm run typecheck`
Expected: errors in `src/register/SaleScreen.tsx` (and any Z-report screen reading the old
maps). Those are Task 16's job — note them and move on; do not patch them here.

- [ ] **Step 8: Commit**

```bash
git add frontend/web/src/lib/api.ts frontend/web/src/lib/api.test.ts
git commit -m "feat(register): API client tenders by payment method code"
```

---

## Task 16: Register tender buttons from the catalog

**Files:**
- Modify: `frontend/web/src/register/SaleScreen.tsx`
- Modify: any screen reading the Z-report's driver maps (find with `grep -rn "sales_by_driver\|refunds_by_driver" frontend/web/src`)
- Test: `frontend/web/src/register/SaleScreen.test.tsx`

**Interfaces:**
- Consumes: `api.catalog()` → `payment_methods` and the new `api.takePayment` signature (Task 15).
- Produces: the tender step rendering one button per active method, grouped by group name when the location has more than one group.

- [ ] **Step 1: Write the failing test**

**Read this first — it affects the whole file.** `SaleScreen.test.tsx`'s `vi.mock('../lib/api')`
factory spreads `...actual.api` and mocks only `findOrders`, `splitOrder`, `takePayment` and
`receipt`. `api.catalog` is deliberately left real, because nothing in this file's render
path called it (`can={() => false}` disables the discounts query). Task 16 adds an
**ungated** catalog query, so every test in this file would fire a real `api.catalog()`,
get a rejected promise, resolve `methods` to `[]`, and render the no-methods empty state
instead of a tender form — breaking the existing split and take-payment cases.

So first add `catalog: vi.fn()` to that mock factory's `api` object, and mock it in
`beforeEach` for the whole file:

```tsx
beforeEach(() => {
  vi.clearAllMocks()
  setCurrency('USD')
  // SaleScreen now reads its tender buttons from the catalog, ungated — so this must be
  // mocked for every case in this file, not just the tender ones.
  vi.mocked(api.catalog).mockResolvedValue({
    categories: [], products: [], variants: [], modifier_groups: [], modifiers: [],
    tax_rates: [], discounts: [], payment_methods: PAYMENT_METHODS, currency: 'USD',
  })
})
```

`PAYMENT_METHODS` is defined below — put that `const` at **module scope near the top of the
file**, above `beforeEach`, since the mock above reads it. Then append the new cases, using
the file's real `renderSale(initialOrder?)` helper:

```tsx
// Module scope, above beforeEach.
const PAYMENT_METHODS = [
  { id: 'm-cash', code: 'CASH', name: 'Cash', group_code: 'CASH', group_name: 'Cash', driver: 'cash' as const, sort_order: 0 },
  { id: 'm-visa', code: 'VISA', name: 'Visa', group_code: 'CARD', group_name: 'Cards', driver: 'external_card' as const, sort_order: 0 },
  { id: 'm-gcash', code: 'GCASH', name: 'GCash', group_code: 'EWALLET', group_name: 'E-wallets', driver: 'external_card' as const, sort_order: 0 },
]

describe('SaleScreen tender methods', () => {
  // Getting to the tender phase is the same click the file's split cases already use:
  // resume an order, then press the Pay button (which is labelled `Pay — <amount>`).
  async function enterTender() {
    vi.mocked(api.findOrders).mockResolvedValue([])
    renderSale(order)
    await userEvent.click(await screen.findByRole('button', { name: /^Pay — / }))
  }

  it('renders one tender button per method, under its group name', async () => {
    await enterTender()

    expect(screen.getByRole('button', { name: 'Cash' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Visa' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'GCash' })).toBeInTheDocument()
    // Group headings appear because this location has more than one group.
    expect(screen.getByText('E-wallets')).toBeInTheDocument()
  })

  it('shows the cash field for a cash-driver method and a reference field otherwise', async () => {
    await enterTender()

    // Cash is selected first — it is the first method in the (server-sorted) list.
    expect(screen.getByLabelText(/cash tendered/i)).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'GCash' }))
    expect(screen.getByLabelText(/reference/i)).toBeInTheDocument()
    expect(screen.queryByLabelText(/cash tendered/i)).not.toBeInTheDocument()
  })

  it('posts the selected method code', async () => {
    vi.mocked(api.takePayment).mockResolvedValue({
      payment: {
        id: 'p-1', driver: 'external_card', payment_method_code: 'VISA',
        payment_method_name: 'Visa', status: 'captured',
        amount_cents: 1300, tendered_cents: null, change_cents: null,
      },
      order: { ...order, paid_cents: 1300, due_cents: 0, status: 'closed', version: 2 },
    })
    vi.mocked(api.receipt).mockResolvedValue(null as never)

    await enterTender()
    await userEvent.click(screen.getByRole('button', { name: 'Visa' }))
    await userEvent.click(screen.getByRole('button', { name: /take payment/i }))

    expect(api.takePayment).toHaveBeenCalledWith(
      expect.anything(), expect.any(Number), 'VISA', expect.any(String), expect.anything(),
    )
  })

  it('names the back office when the location has no methods', async () => {
    vi.mocked(api.catalog).mockResolvedValue({
      categories: [], products: [], variants: [], modifier_groups: [], modifiers: [],
      tax_rates: [], discounts: [], payment_methods: [], currency: 'USD',
    })

    await enterTender()

    expect(screen.getByText(/no payment methods/i)).toBeInTheDocument()
  })
})
```

Match the real `Pay — ` button label and the real `Order` fixture name (`order`) in that
file; if the resume path needs `api.findOrders` mocked differently than above, follow what
the neighbouring split cases do.

- [ ] **Step 2: Run it to confirm it fails**

Run: `cd frontend/web && npm test -- SaleScreen`
Expected: FAIL — no button named `Visa`.

- [ ] **Step 3: Read the location's methods**

In `frontend/web/src/register/SaleScreen.tsx`, the screen already has a catalog query
(`queryKey: ['catalog-discounts']`) that is gated on `can('order.discount.apply')` — a
cashier without that permission must still be able to take payment, so add a second,
ungated query rather than widening that one. React Query dedupes both against the same
`api.catalog()` request only if they share a key, so give this one its own key and rely on
the shared HTTP cache:

```tsx
  // Tender buttons are admin-configured data (payment_method_groups / payment_methods),
  // fetched with the catalog. Ungated: taking payment needs no discount permission.
  const paymentMethods = useQuery({
    queryKey: ['catalog'],
    queryFn: () => api.catalog(),
    staleTime: 5 * 60_000,
    select: (catalog) => catalog.payment_methods,
  })
  const methods = paymentMethods.data ?? []
```

`['catalog']` is the key `Register.tsx` and `MenuGrid.tsx` already use, so this shares
their cache entry instead of firing a third request.

- [ ] **Step 4: Replace the driver state with a selected method code**

Replace `const [driver, setDriver] = useState<Driver>('cash')` with:

```tsx
  // The method the cashier picked, by code. Null until the methods land; the first
  // method in the (already sorted) list is selected as soon as they do.
  const [methodCode, setMethodCode] = useState<string | null>(null)
```

Add, after `methods` is defined:

```tsx
  const selected = methods.find((m) => m.code === methodCode) ?? methods[0] ?? null

  // Reset to the location's first method whenever the tender phase opens, so a card
  // selection from the previous sale never carries into the next one.
  useEffect(() => {
    if (phase.name === 'tender') setMethodCode(methods[0]?.code ?? null)
  }, [phase.name, methods])
```

- [ ] **Step 5: Post the method code**

In the `pay` mutation, replace the driver branch:

```tsx
      const method = selected as CatalogPaymentMethod
      const outcome =
        method.driver === 'cash'
          ? await api.takePayment(current, amount, method.code, key, {
              tenderedCents: parseCentsOrNull(tendered) ?? 0,
            })
          : await api.takePayment(current, amount, method.code, key, {
              reference: reference.trim() || undefined,
            })
```

Add `CatalogPaymentMethod` to the `api` import list.

- [ ] **Step 6: Update the pay guard**

In `doPay`, replace the driver check:

```tsx
  const doPay = () => {
    if (!order || phase.name !== 'tender' || pay.isPending) return
    if (selected === null) return setError('This location has no payment methods. Add one in the back office.')
    if (selected.driver === 'cash' && parseCentsOrNull(tendered) === null) {
      return setError('Enter the cash handed over, like 50.00')
    }
    setError(null)
    pay.mutate({ key: phase.key })
  }
```

- [ ] **Step 7: Replace the two-button toggle**

Replace the `role="group" aria-label="Payment method"` block and the `driver === 'cash' ?`
input branch beneath it:

```tsx
            {methods.length === 0 ? (
              <p className="type-body-sm text-ink-muted">
                No payment methods are set up for this location. Add one in the back
                office before taking payment.
              </p>
            ) : (
              <>
                {/* ONE role="group" named "Payment method", wrapping every group. Keeping
                    the existing label matters: SaleScreen.test.tsx and
                    docs/user-manual/capture_screenshots.mjs both select on it, and nesting
                    a second group role per bucket would break both for no a11y gain. */}
                <div className="flex flex-col gap-md" role="group" aria-label="Payment method">
                  {groupedMethods.map(([groupName, groupMembers]) => (
                    <div key={groupName} className="flex flex-col gap-xs">
                      {/* One group needs no heading — the buttons are the whole story. */}
                      {groupedMethods.length > 1 && (
                        <span className="type-body-sm text-ink-muted">{groupName}</span>
                      )}
                      <div className="flex flex-wrap gap-sm">
                        {groupMembers.map((method) => (
                          <Button
                            key={method.id}
                            type="button" size="lg" className="flex-1"
                            variant={selected?.code === method.code ? 'primary' : 'tertiary'}
                            aria-pressed={selected?.code === method.code}
                            onClick={() => setMethodCode(method.code)}
                          >
                            {method.name}
                          </Button>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>

                {selected?.driver === 'cash' ? (
                  <label className="block">
                    <span className="type-body-sm text-ink-muted">Cash tendered (owed: {fm(balance)})</span>
                    <Input
                      value={tendered} onChange={(e) => setTendered(e.target.value)} inputMode="decimal" autoFocus
                      className="type-money mt-xs h-[56px] text-[24px]"
                    />
                  </label>
                ) : (
                  <label className="block">
                    <span className="type-body-sm text-ink-muted">
                      {selected?.name} reference (owed: {fm(balance)})
                    </span>
                    <Input
                      value={reference} onChange={(e) => setReference(e.target.value)} placeholder="auth 004321" autoFocus
                      className="mt-xs h-[56px]"
                    />
                  </label>
                )}
              </>
            )}
```

Add this module-level helper near the top of the file:

```tsx
/**
 * Methods bucketed by group name, preserving the server's order — the catalog already
 * sorted by group then method, so first appearance is the group's own rank.
 */
function groupMethods(methods: CatalogPaymentMethod[]): Array<[string, CatalogPaymentMethod[]]> {
  const buckets = new Map<string, CatalogPaymentMethod[]>()
  for (const method of methods) {
    const bucket = buckets.get(method.group_name)
    if (bucket) bucket.push(method)
    else buckets.set(method.group_name, [method])
  }
  return [...buckets.entries()]
}
```

Define `const groupedMethods = groupMethods(methods)` alongside `selected`, above the JSX —
the block above already reads that name rather than calling the helper per render.

- [ ] **Step 8: Update the outcome copy**

The outcome screen branches on `payment.driver === 'cash'` in two places
(around the change-due hero and the split-child summary). Replace the card-side copy so it
names the method instead of the driver:

```tsx
              ? `${fm(payment.amount_cents)} paid on ${fm(payment.tendered_cents ?? payment.amount_cents)} tendered`
              : `${fm(payment.amount_cents)} recorded on ${payment.payment_method_name}`}
```

Apply the same substitution to the second occurrence (`outcome.payment...`). Keep the
`driver === 'cash'` branch condition itself — cash is still what "change due" means.

- [ ] **Step 9: Fix any Z-report reader**

Run `grep -rn "sales_by_driver\|refunds_by_driver" frontend/web/src`. Every hit becomes
`sales_by_method` / `refunds_by_method`, and any hardcoded `'cash'` / `'external_card'`
key becomes the method code. If a screen labels the rows, prefer the map's own key over a
lookup table — the codes are already human-readable.

- [ ] **Step 10: Run the register tests**

Run: `cd frontend/web && npm test -- SaleScreen`
Expected: PASS.

- [ ] **Step 11: Typecheck, full suite, build**

Run: `cd frontend/web && npm run typecheck && npm test && npm run build`
Expected: all clean.

- [ ] **Step 12: Try it at the till**

With `make dev` up and `make seed` run, open <http://127.0.0.1:5174>, activate a register,
sign in with PIN `1111`, scan an item, and go to tender. Expected: Cash / Visa /
Mastercard / GCash / Maya under three group headings; Cash shows the tendered field and
computes change, GCash shows a reference field.

- [ ] **Step 13: Commit**

```bash
git add frontend/web/src/register frontend/web/src/lib
git commit -m "feat(register): tender buttons from the location's payment methods"
```

---

## Task 17: End-to-end scripts

**Files:**
- Modify: `scripts/e2e-retail-day.sh`, `scripts/e2e-lunch-service.sh`, `scripts/e2e-admin-day.sh`

**Interfaces:**
- Consumes: every wire change from Tasks 4, 5, 8, 9, 10.
- Produces: three green scripts under `make e2e`.

- [ ] **Step 1: Update the retail script**

In `scripts/e2e-retail-day.sh`:
- line ~42: `\"driver\":\"cash\"` → `\"payment_method_code\":\"CASH\"`
- line ~49: `\"driver\":\"external_card\"` → `\"payment_method_code\":\"VISA\"` (the seeded grocery location carries VISA, so this now proves a *named* card scheme rather than a bare driver)
- line ~54: the refund body's `\"driver\":\"cash\"` → `\"payment_method_code\":\"CASH\"`
- line ~61: `.data.sales_by_driver` → `.data.sales_by_method`, `.data.refunds_by_driver` → `.data.refunds_by_method`

- [ ] **Step 2: Update the lunch script**

In `scripts/e2e-lunch-service.sh`:
- lines ~139, ~142, ~174: `\"driver\":\"cash\"` → `\"payment_method_code\":\"CASH\"`
- line ~145: `\"driver\":\"external_card\"` → `\"payment_method_code\":\"GCASH\"` — a real e-wallet crosses the wire at least once, and it proves the EWALLET group rolls up separately from CARD despite sharing a driver
- Any `sales_by_driver` / `refunds_by_driver` read → `sales_by_method` / `refunds_by_method`

- [ ] **Step 3: Add a payment-method beat to the admin script**

In `scripts/e2e-admin-day.sh`, after the existing catalog/user beats and before the
reports beat, add (matching the script's own `req`/`API`/header variables and its numbered
`echo` style — renumber the following steps):

```bash
# Payment methods: configure a tender in the back office, see the till offer it, take it.
GRP=$(req POST "/admin/payment-method-groups" -H "$ADMIN" -H "$J" \
  -d "{\"location_id\":\"$LOCATION_ID\",\"code\":\"VOUCHER\",\"name\":\"Vouchers\",\"driver\":\"external_card\",\"sort_order\":9}" \
  | jq -r .data.payment_method_group.id)
MTH=$(req POST "/admin/payment-methods" -H "$ADMIN" -H "$J" \
  -d "{\"location_id\":\"$LOCATION_ID\",\"group_id\":\"$GRP\",\"code\":\"MEALVOUCHER\",\"name\":\"Meal voucher\"}" \
  | jq -r .data.payment_method.id)
echo "N. payment method created: group=VOUCHER method=MEALVOUCHER id=$MTH"

# The till sees it without a deploy — that is the whole point of the taxonomy being data.
CAT=$(req GET "/catalog" -H "$D")
echo "N+1. till offers it: $(echo "$CAT" | jq -r '[.data.payment_methods[].code] | join(",")')"

# Archive it and watch the till stop offering it.
req PATCH "/admin/payment-methods/$MTH" -H "$ADMIN" -H "$J" -d '{"is_active":false}' > /dev/null
CAT2=$(req GET "/catalog" -H "$D")
echo "N+2. archived, till no longer offers it: $(echo "$CAT2" | jq -r '[.data.payment_methods[].code] | join(",")')"
```

Use the script's real variable names for the admin bearer header, the device header, and
the location id — read the top of the file first and substitute; `$ADMIN`, `$D`, `$J` and
`$LOCATION_ID` above are placeholders for whatever it already calls them.

- [ ] **Step 4: Run all three scripts**

Run: `make e2e`
Expected: all three green. If the retail script's Z-report numbers shift because the card
tender moved to `VISA`, only the *keys* change, never the amounts — update the expected key
in the echo, not the arithmetic.

- [ ] **Step 5: Commit**

```bash
git add scripts/e2e-retail-day.sh scripts/e2e-lunch-service.sh scripts/e2e-admin-day.sh
git commit -m "test(e2e): tender by method code, prove a back-office method reaches the till"
```

---

## Task 18: Documentation

The docs are the source of truth for this project, not a trailing artifact — the GitHub
wiki is generated from `docs/` by CI (`scripts/wiki-sync.sh`), so edit here and never there.

**Files:**
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/01-architecture.md`, `docs/05-rbac.md`, `docs/06-roadmap.md`, `CLAUDE.md`

**Interfaces:**
- Consumes: everything Tasks 1–17 built.
- Produces: no code. The endpoint list in `03-api.md` and `Permissions::all()` must be the same list; the error table in `03-api.md` and `app/Exceptions/Domain/` must be diffable.

- [ ] **Step 1: `docs/02-data-model.md` — the new tables**

Add a `### Payment methods` section immediately **before** the existing `### Payments`
section. Include the two `create table` statements exactly as built in Task 1 (they are the
schema of record), then the prose that carries the reasoning:

- The group is the behavioural bucket: it names one driver, its methods are variants that
  behave identically. `CARD` and `EWALLET` may both drive `external_card` and still be
  separate groups, because a drawer count needs GCash apart from Visa.
- `location_id` is on both tables because the uniqueness rule is per-location; the
  composite FK `(group_id, location_id)` is what keeps that duplicate honest.
- `code` on both, and `group_id` on a method, are immutable after create — a code is a wire
  identifier and a report key, and the group *is* the behaviour. Names are editable.
- Archiving a group hides its methods without touching their rows.

Then, in the `payments` and `refunds` `create table` blocks, add the three new columns with
comments, and add a paragraph explaining that code and name are snapshots (the order-lines
rule applied to tenders) while `driver` survives as a derived column so
`ShiftTotals`/`payments_change_balances` are untouched.

Finally, add two bullets to the closing **"What the schema refuses to allow"** list:

```markdown
- A payment method cannot belong to another location's group. (Composite foreign key.)
- Two methods, or two groups, cannot share a code at one location — while the same code at
  two different locations is legal and expected. (Unique index on `(location_id, code)`.)
```

- [ ] **Step 2: `docs/03-api.md` — the wire changes**

Four edits:

1. **Catalog** — add `payment_methods` to the documented `GET /catalog` payload, noting
   active-in-active-group only and the total ordering.
2. **Payments** — replace the `driver` request examples with `payment_method_code`, and
   document that the driver is derived from the method's group and returned (not sent).
   Keep the `amount_cents` vs `tendered_cents` paragraph exactly as it is — that
   distinction did not change.
3. **Refunds** — replace `driver: "cash"` with `payment_method_code`, and replace the
   "`driver: "external_card"` is rejected" sentence with: refundability comes from the
   method's driver capability, so a method in any non-refundable group is refused with
   `422 refund_method_not_refundable`.
4. **Reports** — the Z-report line becomes
   `→ { shift, sales_by_method, sales_by_group, refunds_by_method, refunds_by_group, movements, orders_closed, orders_voided, orders_split, expected_cash_cents }`,
   and `group_by=day|category|user` becomes `group_by=day|category|user|payment_method`
   with a sentence saying `payment_method` is ledger-basis like `day`/`user` and groups on
   the snapshot columns.

Add the back-office block in the `/admin/*` section, next to locations:

```markdown
### Payment methods — `payment_method.manage`

    GET   /admin/payment-method-groups?location_id=
    POST  /admin/payment-method-groups   { location_id, code, name, driver, sort_order }
    PATCH /admin/payment-method-groups/{group}   { name?, sort_order?, is_active? }

    GET   /admin/payment-methods?location_id=
    POST  /admin/payment-methods   { location_id, group_id, code, name, sort_order }
    PATCH /admin/payment-methods/{method}   { name?, sort_order?, is_active? }

`code` and `driver` (group) and `code` and `group_id` (method) are absent from the PATCH
bodies because they are immutable after create — changing a group's driver would change
how every method under it behaves and retroactively re-bucket history. Codes are
normalized to uppercase and are unique per location. Both `location_id`s are checked
against where the caller actually holds the permission, like the report endpoints.
```

And add three rows to the error-code table:

| code | status | meaning |
| --- | --- | --- |
| `payment_method_unknown` | 422 | No method with this code at this location |
| `payment_method_inactive` | 422 | The method, or its group, is archived |
| `refund_method_not_refundable` | 422 | This method's driver cannot return money through us |

- [ ] **Step 3: `docs/01-architecture.md` — the seam is unchanged, on purpose**

In the **Payment driver contract** section, after the "v1 ships: cash / external_card"
list, add:

```markdown
Groups and methods (`02-data-model.md`) sit **above** this seam, not inside it. A payment
method group is per-location admin data naming exactly one driver; its methods are names an
admin gives to behaviour the driver already implements. That split is what decides where new
work goes: a second e-wallet or a named card scheme is a **row**, because it behaves
identically to something already here; Stripe Terminal is still a **driver class**, because
it behaves differently. Nothing in `PaymentDriver`, `DriverRegistry` or `Capabilities`
knows that methods exist — `PaymentMethodResolver` turns a code into a driver and the rest
of the payment path is untouched.
```

- [ ] **Step 4: `docs/05-rbac.md` — the new permission**

Add `payment_method.manage` to the permission catalog table with the same shape as
`day.close`: admin-tier, granted by no default role, doubles as the back-office section,
**not** money-leaves. Add a sentence explaining why it is not money-leaves: naming a tender
moves no money, and every payment taken on one is still gated by `payment.take` and
recorded against a user and a shift. Note that its `location_id` scoping follows the report
rule — holding it somewhere gets a non-admin into the section, not into every store's
tenders.

- [ ] **Step 5: `docs/06-roadmap.md` — the record**

Add a "Payment methods complete" entry in the same voice as the existing milestone records:
what shipped, the one design decision worth remembering (the group carries the driver, so
the code seam never moved), the bug class it closed by provisioning defaults on
`CreateLocation`, the breaking wire change (`driver` → `payment_method_code`) and that all
three e2e scripts were updated, plus the final suite counts.

- [ ] **Step 6: `CLAUDE.md` — the Status entry**

Add a paragraph after the End Of Day entry, in the same voice, covering: the two tables, the
group-carries-the-driver decision, `driver` surviving as a derived column, the immutability
rules, the new permission and section, the Z-report and sales-report groupings, and the
final suite counts. Do **not** edit the M2 "40 tables" claim — it describes M2 and is
still true of M2.

Also add one bullet to **"Gotchas that will cost you an afternoon"**:

```markdown
- **A payment method's group carries the driver, and neither a code nor a group is
  editable.** Moving a method between groups would silently change its behaviour and
  retroactively re-bucket every payment taken on it, so `UpdatePaymentMethod` refuses
  `group_id` and `code` outright — the fix for a wrong one is archive-and-recreate. The
  same applies to a group's own `code` and `driver`.
```

- [ ] **Step 7: Verify the two lists agree**

Run:

```bash
grep -c "public const string" backend/app/Domain/Rbac/Permissions.php
grep -n "payment_method" docs/03-api.md docs/05-rbac.md
grep -rn "errorCode" backend/app/Exceptions/Domain/PaymentMethod*.php backend/app/Exceptions/Domain/RefundMethod*.php
```

Expected: every new permission and error code appears in both the code and the docs. An
endpoint with no permission, or an exception with no table row, is a bug in one of the two.

- [ ] **Step 8: Commit**

```bash
git add docs/01-architecture.md docs/02-data-model.md docs/03-api.md docs/05-rbac.md \
        docs/06-roadmap.md CLAUDE.md
git commit -m "docs: payment method groups and methods"
```

---

## Task 19: User manual

**Files:**
- Modify: `docs/user-manual/user-manual.md`, `docs/user-manual/glossary.md`
- Modify: `docs/user-manual/capture_screenshots.mjs`
- Regenerate: `docs/user-manual/assets/screenshots/*`, `docs/user-manual/user-manual.pdf`

**Interfaces:**
- Consumes: the shipped UI from Tasks 14 and 16.
- Produces: a cashier passage on choosing a tender, an admin chapter section on configuring methods, two glossary entries, one new screenshot, and a rebuilt PDF.

- [ ] **Step 1: Rewrite the cashier tender passage**

In `docs/user-manual/user-manual.md`, Chapter 5's **"Pay, tender, and print"** currently
documents a fixed Cash/Card pair. Replace the two bullets with copy that matches what the
till now renders — buttons the store configured, grouped by group name — while keeping the
two existing figures (5.5 and 5.6) and their captions:

```markdown
Tap **Pay — [amount]** (the button shows exactly what's owed).

The tender buttons are whatever your store set up (Chapter 12) — often **Cash**, a
card scheme or two, and an e-wallet — grouped under headings like **Cards** or
**E-wallets**. What you type next depends on which one you tap:

- **A cash method:** type what the customer handed you into **Cash tendered (owed:
  …)**, then tap **Take payment**. The next screen works out **Change** for you —
  never do that math yourself.

- **Anything else** (a card, an e-wallet, a voucher): type the reference the other
  machine gave you into **[method] reference (owed: …)**, then tap **Take payment**.
  This till only *records* what that machine already did — it doesn't talk to it.

If the tender step says there are no payment methods, nobody has set any up for this
location yet; an admin fixes that in Chapter 12.
```

- [ ] **Step 2: Add the admin section**

Chapter 11 is "Locations and registers" and Chapter 12 is "Reports". Insert a new
**"# 12. Payment methods"** chapter between them and renumber every later chapter heading
and cross-reference (`# 13. Reports`, `# 14. Audit log`, `# 15. End of Day`, and the
chapter numbers referenced in the Chapter 5 copy above and anywhere else in the file — grep
for `Chapter 1[0-9]`).

The chapter covers, in this order:

```markdown
# 12. Payment methods

Every location decides for itself what it accepts. A **payment method group** is a
kind of tender — Cash, Cards, E-wallets — and a **payment method** is a name inside
it: Visa and Mastercard under Cards, GCash and Maya under E-wallets.

The split matters because the *group* is what decides behaviour. A cash group opens
the drawer and works out change. Every other group records what another machine
already did, and money taken on it can't be refunded through this till — the money
never came through here.

## Add a method

Pick the location in the sidebar first: these are that location's tenders, and two
locations can use the same codes for different things.

**New group** asks for a code (like `EWALLET`), a name customers would recognise, and
which of the two behaviours it has. **New method** inside a group asks only for a code
and a name — it inherits the group's behaviour, which is the point.

Codes are letters, digits and underscores, and they're what your reports are grouped
by, so pick them once and keep them.

## What you can change later, and what you can't

Names and order can change whenever you like — they're what staff read.

Codes can't, and neither can a method's group. A code is what the till sends and what
your reports are keyed on; a group is the behaviour. Changing either after money has
moved would quietly rewrite history. If one is wrong, archive it and add the right one.

## Archive, never delete

Archiving a method takes it off the till and leaves every past sale intact. Archiving
a whole *group* takes all of its methods off the till at once — one switch for "we've
stopped taking cards" — and reactivating it brings back exactly the ones that were
live before.

A location with nothing active can't take payment at all. That's allowed (a card-only
kiosk is a real thing), so nothing stops you — the till just says so.

## Reading it back

The Z-report at the till (Chapter 7) breaks the drawer down by method *and* by group,
which is why Cards and E-wallets are worth keeping apart even though they behave the
same. In the back office, **Reports → Sales** grouped by **Payment method** answers
"how much came in on GCash last month".
```

- [ ] **Step 3: Add the glossary entries**

In `docs/user-manual/glossary.md`, in alphabetical position:

```markdown
**Payment method** — A named way of paying at one location: Cash, Visa, GCash. It
belongs to a payment method group, which is what decides how it behaves. Its code is
fixed once created; its name isn't.

**Payment method group** — A kind of tender at one location — Cash, Cards, E-wallets —
holding one or more payment methods. The group decides the behaviour: a cash group opens
the drawer and computes change, every other kind only records what another machine did
and can't be refunded through the till.
```

- [ ] **Step 4: Add a screenshot capture**

In `docs/user-manual/capture_screenshots.mjs`, in the back-office leg, after the
`027-bo-locations` / `028-bo-registers` pair and before the register-editor captures:

```js
  await nav('Payment methods').click();
  await page.waitForTimeout(600);
  await shot(page, '035-bo-payment-methods');
```

`035` continues past the current highest (`034-bo-end-of-day`) rather than renumbering the
existing files. The retail leg needs no change: it already waits on
`getByRole('group', { name: 'Payment method' })` and fills `/^Cash tendered/`, both of
which Task 16 preserved.

- [ ] **Step 5: Reference the figure**

In the new Chapter 12, under **"Add a method"**, add:

```markdown
![Figure 12.1 — Payment methods for the Manila Restaurant: three groups, five methods](assets/screenshots/035-bo-payment-methods.png)
```

- [ ] **Step 6: Recapture and rebuild**

Run, with `make dev` up and a fresh seed:

```bash
make seed
make manual-shots
make manual
```

Expected: `035-bo-payment-methods.png` exists, the retail tender shot still shows the
tender step (now with the seeded buttons), and `docs/user-manual/user-manual.pdf` rebuilds.

- [ ] **Step 7: Read the new pages**

Open `docs/user-manual/user-manual.pdf` and check the new chapter renders: heading level
right, figure placed, no orphaned cross-reference to an old chapter number
(`grep -n "Chapter 1[0-9]" docs/user-manual/user-manual.md` and verify each still points at
the right chapter after the renumbering).

- [ ] **Step 8: Bump the revision history**

`docs/user-manual/user-manual.md` opens with a **Revision History** table. Add a row for
this change in the same format as the existing rows.

- [ ] **Step 9: Commit**

```bash
git add docs/user-manual
git commit -m "docs(manual): payment methods chapter and tender copy"
```

---

## Final verification

- [ ] **Step 1: Everything, in containers**

Run: `make test`
Expected: all three suites green.

- [ ] **Step 2: All three e2e proofs**

Run: `make e2e`
Expected: three green scripts.

- [ ] **Step 3: A migration from scratch, then a restore drill**

```bash
make seed              # migrate:fresh --seed — proves both migrations run on an empty db
make restore-drill     # proves the dump still restores with the new tables in it
```

- [ ] **Step 4: Production images still build**

Run: `make build`
Expected: all three images build.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin feature/payment-methods
gh pr create --title "Payment methods: per-location tender groups and methods" --body "$(cat <<'EOF'
## Summary

Per-location payment method groups (group code + one driver) holding payment methods
(method code), both unique per location. The group carries the driver, so the
`PaymentDriver` seam never moved: a new *name* for existing behaviour is a row, a new
behaviour is still a driver class.

- Two new tables; `payments`/`refunds` gain a method FK plus snapshot code/name and keep
  `driver` as a derived column, so all drawer-variance math is untouched
- `driver` leaves the payment and refund request bodies in favour of
  `payment_method_code`; the driver is resolved server-side from the method's group
- Refundability now comes from `Capabilities::refundable` rather than an `in:cash`
  validation string
- New `payment_method.manage` permission and back-office section; Z-report breaks down by
  method and group; sales report gains `group_by=payment_method`
- `CreateLocation` provisions a default tender set — a location created in the back office
  with no methods could take no payment, the same bug class RBAC v2 fixed for roles

## Testing

`make test` green, `make e2e` green (all three scripts updated), `make build` green,
`make restore-drill` green.

Spec: `docs/superpowers/specs/2026-07-26-payment-methods-design.md`
Plan: `docs/superpowers/plans/2026-07-26-payment-methods.md`
EOF
)"
```
