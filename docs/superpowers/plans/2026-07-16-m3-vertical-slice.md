# M3 — Vertical Slice Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A real sale runs end-to-end in a browser — open shift, scan barcode, add line, pay cash, get change, print receipt, close shift with the drawer reconciling — proving money, snapshots, stock locking, idempotency, shifts, and auth *together*.

**Architecture:** Laravel action-class pattern (route → 3-line controller → FormRequest → Action → Resource), per `docs/04-backend-conventions.md`. New domain services (`PriceResolver`, `StockLedger`, `OrderTotals`, `OrderNumbers`, `ShiftTotals`, payment drivers) sit under `app/Domain/`. React SPA gets a register flow as a small state machine over server-computed order state — the client never does money math.

**Tech Stack:** Laravel 13.20 / PHP 8.5, PostgreSQL 18 (real Postgres in tests, never SQLite), Pest, React 19 + TS 7 + Vite 8.

## Global Constraints

Copied from the docs; every task inherits these.

- **Money is integer cents** everywhere (`bigint`/PHP `int`/JSON int with `_cents` suffix). Use `App\Domain\Money\Money`; all rounding through `Money::fraction()` (used via `multipliedByQuantity`, `TaxRate::taxOnNet/taxOnGross`).
- **Quantities are strings** on the wire and `numeric(12,3)` in Postgres. Validate with `['required','string','regex:/^\d+(\.\d{1,3})?$/']`, never `numeric`.
- **The client never sends a total.** It sends intent; the server computes and returns money.
- **One system action = one route = one single-action controller = one Action class.** Actions take an Input DTO, never touch HTTP, own their `DB::transaction()`, return domain objects. Controllers are 3 lines.
- **Every file:** `declare(strict_types=1);` and actions are `final` — `tests/Arch/ConventionsTest.php` enforces this mechanically.
- **Never call `env()` outside `config/`.**
- **Financial records are append-only.** Payments' `amount_cents` immutable; lines are voided, never deleted; refunds are new rows.
- **Order lines snapshot** name/SKU/price/tax-rate at add time. Receipts read only snapshots.
- **Version check inside the transaction, after the row lock** — never in the FormRequest (TOCTOU).
- **Idempotency middleware opens the transaction the action nests in**; only 2xx stores a key.
- **Tests run against real Postgres** (`pos_test`). A constraint violation aborts the whole test transaction — one violation per test, nothing after it.
- **Never read spatie roles via `roles()` relation**; permission team context must be set before any `can()` (`EnsureStaffSession` does it on the HTTP path).
- Error envelope: `{"error":{"code","message","details"}}` via `DomainException` subclasses; each stable `code` is one class in `app/Exceptions/Domain/`.
- Wire timestamps ISO-8601 with offset; IDs are UUIDv7 (DB `uuidv7()` default, models use `HasUuids`).

## Deliberate M3 scope cuts (per roadmap sequencing — do NOT build these)

- **Modifiers on add-line** → M5. `AddLineRequest` marks `modifiers` prohibited; `modifiers_total_cents` stays 0.
- **Discounts, line PATCH/DELETE, order/payment voids, reopen, refunds, external_card driver, Z-report, cash-movements endpoint, approve-variance endpoint** → M4/M5. (`ShiftTotals` still *queries* `cash_movements`/`refunds` so the math is right the day those endpoints exist.)
- **`GET /orders` list + `GET /orders/{id}`** → M5 floor view. The register keeps order state from mutation responses.
- **Catalog `updated_since` delta sync** → deferred: `modifiers` has no `updated_at`, so deltas are half-broken by schema; registers full-sync a tiny payload. Note in docs.
- **`location_id` on catalog endpoints is ignored** — the enrolled register's own location is authoritative (a device choosing its pricing location would be a tampering vector). Back office revisits in M6.
- **Rate limits beyond `pin` + `catalog`** → M7 hardening.

## File Structure

```
backend/app/
  Models/                 Shift, Order, OrderLine, Payment, OrderStatus (enum), IdempotencyKey
  Http/Middleware/        EnsureIdempotency
  Domain/Pricing/         PriceResolver, OrderTotals
  Domain/Stock/           StockLedger
  Domain/Orders/          OrderNumbers
  Domain/Shifts/          ShiftTotals
  Domain/Payments/        PaymentDriver, Capabilities, PaymentIntent, PaymentResult, CashDriver, DriverRegistry
  Actions/Shifts/         OpenShift(+Input), GetCurrentShift, CurrentShiftStatus, CloseShift(+Input)
  Actions/Orders/         OpenOrder(+Input), AddLineToOrder(+Input), GetReceipt
  Actions/Catalog/        GetCatalog, CatalogSnapshot, LookupBarcode, ResolvedVariant
  Actions/Payments/       TakePayment(+Input)
  Http/Controllers/       Shifts/, Orders/, Catalog/, Payments/ — one __invoke class per action
  Http/Requests/          one per mutating action
  Http/Resources/         ShiftResource, CurrentShiftResource, CloseShiftResource, OrderResource,
                          OrderLineResource, TakePaymentResource, CatalogResource,
                          ResolvedVariantResource, ReceiptResource
  Exceptions/Domain/      ShiftAlreadyOpen, ShiftAlreadyClosed, ShiftHasOpenOrders, NoOpenShift,
                          OrderClosed, OrderVersionConflict, InsufficientStock,
                          PaymentExceedsBalance, IdempotencyKeyReused
frontend/web/src/
  lib/api.ts              extended: tokens, headers, all M3 endpoints + types
  register/SessionScreens.tsx   device setup + PIN login
  register/ShiftScreens.tsx     open / close shift
  register/SaleScreen.tsx       scan → cart → tender → change → receipt
  App.tsx                 state machine over the screens
```

---

### Task 1: Sales models, factories, and test helpers

**Files:**
- Create: `backend/app/Models/OrderStatus.php`, `backend/app/Models/Shift.php`, `backend/app/Models/Order.php`, `backend/app/Models/OrderLine.php`, `backend/app/Models/Payment.php`
- Create: `backend/database/factories/ShiftFactory.php`, `backend/database/factories/OrderFactory.php`
- Modify: `backend/tests/Pest.php` (shared helpers)
- Test: `backend/tests/Feature/Schema/SalesModelsTest.php`

**Interfaces:**
- Consumes: M2 migrations (tables exist), `HasUuids` convention from existing models.
- Produces: `OrderStatus::Open|Closed|Voided` (string-backed enum), `Shift`, `Order` (relations `lines()`, `payments()`, `location()`, `register()`, `shift()`, `openedBy()`; casts: all `*_cents` int, `status` → `OrderStatus`, `qty` string), `Order::lines()` ordered by `position`. Pest helpers `provisionedLocation(array $attrs = []): Location`, `staffWithRole(Location, string $role): User`, `registerAt(Location): Register`, `staffHeaders(Register, User): array`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Schema/SalesModelsTest.php
declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Shift;

it('round-trips an order with integer money, string qty, and enum status', function (): void {
    $order = Order::factory()->create(['total_cents' => 1234]);
    $line = $order->lines()->create([
        'variant_id' => \App\Models\ProductVariant::factory()->create()->id,
        'name_snapshot' => 'T-Shirt — Blue / L',
        'sku_snapshot' => 'TSHIRT-BLUE-L',
        'unit_price_cents' => 1999,
        'tax_rate_micros' => 88750,
        'qty' => '0.500',
        'line_total_cents' => 1000,
        'created_at' => now(),
    ]);

    $fresh = Order::with('lines')->findOrFail($order->id);

    expect($fresh->status)->toBe(OrderStatus::Open)
        ->and($fresh->total_cents)->toBeInt()->toBe(1234)
        ->and($fresh->lines->first()->qty)->toBeString()->toBe('0.500')
        ->and($fresh->lines->first()->tax_rate_micros)->toBeInt();
});

it('creates a shift without eloquent timestamps', function (): void {
    $shift = Shift::factory()->create();

    expect($shift->opening_float_cents)->toBeInt()
        ->and($shift->closed_at)->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/SalesModelsTest.php`
Expected: FAIL — `Class "App\Models\Order" not found`.

- [ ] **Step 3: Implement the models, factories, and helpers**

```php
<?php
// app/Models/OrderStatus.php
declare(strict_types=1);

namespace App\Models;

enum OrderStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Voided = 'voided';
}
```

```php
<?php
// app/Models/Shift.php
declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A drawer's session: float in, count out, variance recorded. At most one open per
 * register — enforced by a partial unique index, not application code.
 * See docs/02-data-model.md (cash accountability).
 */
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory, HasUuids;

    // opened_at/closed_at are the real lifecycle; created_at/updated_at don't exist.
    public $timestamps = false;

    protected $fillable = [
        'register_id', 'opened_by', 'opened_at', 'opening_float_cents',
        'closed_by', 'closed_at', 'counted_cash_cents', 'expected_cash_cents',
        'variance_cents', 'close_note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opening_float_cents' => 'integer',
            'counted_cash_cents' => 'integer',
            'expected_cash_cents' => 'integer',
            'variance_cents' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Register, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
```

```php
<?php
// app/Models/Order.php
declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The lifecycle both retail and food service travel, at different speeds.
 * Totals are always server-computed by OrderTotals; `version` is the optimistic lock.
 * See docs/02-data-model.md and docs/03-api.md.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;   // opened_at/closed_at are the lifecycle

    protected $fillable = [
        'number', 'location_id', 'register_id', 'shift_id', 'business_date',
        'opened_by', 'closed_by', 'customer_id', 'table_ref', 'status',
        'prices_include_tax', 'subtotal_cents', 'discount_cents', 'tax_cents',
        'total_cents', 'paid_cents', 'version', 'opened_at', 'closed_at',
        'voided_at', 'void_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'prices_include_tax' => 'boolean',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_cents' => 'integer',
            'version' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'voided_at' => 'datetime',
            // business_date stays the raw 'YYYY-MM-DD' string Postgres returns — it is
            // a local calendar day, and a datetime cast would re-attach a timezone.
        ];
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Register, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
```

```php
<?php
// app/Models/OrderLine.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing sold, with name/SKU/price/tax-rate frozen at add time so a receipt from
 * last year reprints identically. Never joined to the live catalog for display.
 * Voided, never deleted. See docs/02-data-model.md.
 */
class OrderLine extends Model
{
    use HasUuids;

    public const null UPDATED_AT = null;   // append-then-void; no updated_at column

    protected $fillable = [
        'order_id', 'variant_id', 'name_snapshot', 'sku_snapshot',
        'unit_price_cents', 'tax_rate_micros', 'qty', 'modifiers_total_cents',
        'discount_cents', 'tax_cents', 'line_total_cents', 'position',
        'voided_at', 'voided_by', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'tax_rate_micros' => 'integer',
            'qty' => 'string',              // numeric(12,3) -> string; never float
            'modifiers_total_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'line_total_cents' => 'integer',
            'position' => 'integer',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
```

```php
<?php
// app/Models/Payment.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tender against an order. amount_cents is immutable once written — corrections are
 * a void plus a new row, never an update. shift_id makes drawer variance computable.
 * See docs/02-data-model.md.
 */
class Payment extends Model
{
    use HasUuids;

    public const null UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'shift_id', 'driver', 'status', 'amount_cents',
        'tendered_cents', 'change_cents', 'reference', 'driver_payload',
        'user_id', 'created_at', 'captured_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'tendered_cents' => 'integer',
            'change_cents' => 'integer',
            'driver_payload' => 'array',
            'created_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
```

```php
<?php
// database/factories/ShiftFactory.php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\Register;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Shift> */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'register_id' => Register::factory(),
            'opened_by' => User::factory(),
            'opened_at' => now(),
            'opening_float_cents' => 20000,
        ];
    }
}
```

```php
<?php
// database/factories/OrderFactory.php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\Register;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'TT-'.fake()->unique()->numerify('########'),
            'location_id' => Location::factory(),
            'register_id' => Register::factory(),
            'shift_id' => Shift::factory(),
            'business_date' => now()->toDateString(),
            'opened_by' => User::factory(),
            'status' => 'open',
            'prices_include_tax' => false,
            'opened_at' => now(),
        ];
    }

    /** Wire location/register/shift into one consistent chain. */
    public function forRegister(Register $register): static
    {
        return $this->state(fn (): array => [
            'location_id' => $register->location_id,
            'register_id' => $register->id,
            'shift_id' => Shift::factory()->create(['register_id' => $register->id])->id,
        ]);
    }
}
```

Append to `tests/Pest.php` (below the existing `pest()->extend(...)` block). These are global Pest helpers — Pest test files share one process, so helper functions live here once, never per-file:

```php
use App\Domain\Rbac\RoleProvisioner;
use App\Models\Location;
use App\Models\Register;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/** A location with the role/permission catalog provisioned. */
function provisionedLocation(array $attrs = []): Location
{
    $location = Location::factory()->create($attrs);
    $provisioner = app(RoleProvisioner::class);
    $provisioner->provisionGlobal();
    $provisioner->provisionForLocation($location);

    return $location;
}

function registerAt(Location $location): Register
{
    return Register::factory()->create(['location_id' => $location->id]);
}

function staffWithRole(Location $location, string $role): User
{
    $user = User::factory()->create();
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($location->id);
    $user->assignRole($role);
    $registrar->forgetCachedPermissions();

    return $user;
}

/**
 * Device + staff headers for HTTP tests. The staff token's ability string must match
 * what StaffLogin issues and EnsureStaffSession checks: "register:{id}".
 */
function staffHeaders(Register $register, User $user): array
{
    $device = $register->createToken("device:{$register->id}", ['device'])->plainTextToken;
    $staff = $user->createToken("staff:{$user->id}", ["register:{$register->id}"], now()->addMinutes(480))->plainTextToken;

    return ['Authorization' => "Bearer {$device}", 'X-Staff-Token' => $staff];
}
```

Before writing `staffHeaders`, read `app/Actions/Auth/StaffLogin.php` and copy its exact token name and ability format if it differs from the above.

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Schema/SalesModelsTest.php && ./vendor/bin/pest tests/Arch`
Expected: PASS (arch tests confirm strict types etc. on the new files).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Models backend/database/factories backend/tests
git commit -m "M3: sales models, factories, and shared test helpers"
```

---

### Task 2: Idempotency middleware

**Files:**
- Create: `backend/app/Models/IdempotencyKey.php`, `backend/app/Http/Middleware/EnsureIdempotency.php`, `backend/app/Exceptions/Domain/IdempotencyKeyReused.php`
- Modify: `backend/bootstrap/app.php` (add `'idempotent'` alias next to the existing `'device'`/`'staff'` aliases)
- Test: `backend/tests/Feature/Http/IdempotencyTest.php`

**Interfaces:**
- Consumes: `idempotency_keys` table (M2 migration), `DomainException` base.
- Produces: route middleware alias `idempotent`. Contract: no header → pass-through; replay with same body → stored response verbatim, work NOT re-executed; same key + different body → `409 idempotency_key_reused`; non-2xx responses never store a key.

- [ ] **Step 1: Write the failing test**

The test needs a real mutating idempotent route; none exists yet. Register a throwaway route inside the test that increments a counter table — self-contained, no waiting on Task 7.

```php
<?php
// tests/Feature/Http/IdempotencyTest.php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // A minimal idempotent endpoint: every *executed* call inserts an audit_log row
    // (a handy pre-existing table). Replays must not add rows.
    Route::post('/api/v1/_test/idempotent', function () {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'action' => 'test.executed',
            'entity_type' => 'test',
            'created_at' => now(),
        ]);

        return response()->json(['data' => ['ok' => true]], 201);
    })->middleware('idempotent');

    Route::post('/api/v1/_test/failing', function () {
        return response()->json(['error' => ['code' => 'nope', 'message' => '', 'details' => []]], 409);
    })->middleware('idempotent');
});

function executedCount(): int
{
    return DB::table('audit_log')->where('action', 'test.executed')->count();
}

it('passes through without a key', function (): void {
    $this->postJson('/api/v1/_test/idempotent', ['a' => 1])->assertCreated();
    $this->postJson('/api/v1/_test/idempotent', ['a' => 1])->assertCreated();

    expect(executedCount())->toBe(2);
});

it('replays the stored response without re-executing', function (): void {
    $key = (string) Str::uuid();

    $first = $this->postJson('/api/v1/_test/idempotent', ['a' => 1], ['Idempotency-Key' => $key]);
    $second = $this->postJson('/api/v1/_test/idempotent', ['a' => 1], ['Idempotency-Key' => $key]);

    $first->assertCreated();
    $second->assertStatus(201);
    expect($second->json())->toBe($first->json())
        ->and(executedCount())->toBe(1);
});

it('rejects the same key with a different body', function (): void {
    $key = (string) Str::uuid();
    $this->postJson('/api/v1/_test/idempotent', ['a' => 1], ['Idempotency-Key' => $key]);

    $this->postJson('/api/v1/_test/idempotent', ['a' => 2], ['Idempotency-Key' => $key])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    expect(executedCount())->toBe(1);
});

it('does not store a key for a non-2xx response, so a retry may succeed', function (): void {
    $key = (string) Str::uuid();

    $this->postJson('/api/v1/_test/failing', [], ['Idempotency-Key' => $key])->assertStatus(409);

    expect(DB::table('idempotency_keys')->where('key', $key)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Http/IdempotencyTest.php`
Expected: FAIL — `Target class [idempotent] does not exist.`

- [ ] **Step 3: Implement model, exception, middleware, alias**

```php
<?php
// app/Models/IdempotencyKey.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored response for a client-generated key, so a retried mutation returns the
 * original outcome instead of executing twice. The key and the work it guards commit
 * in ONE transaction — EnsureIdempotency opens it. See docs/01-architecture.md.
 */
class IdempotencyKey extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const null UPDATED_AT = null;

    protected $fillable = ['key', 'request_hash', 'response_code', 'response_body', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_body' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
```

```php
<?php
// app/Exceptions/Domain/IdempotencyKeyReused.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

final class IdempotencyKeyReused extends DomainException
{
    public function __construct(private readonly string $key)
    {
        parent::__construct('This Idempotency-Key was already used for a different request.');
    }

    public function errorCode(): string
    {
        return 'idempotency_key_reused';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['key' => $this->key];
    }
}
```

Match the constructor/abstract shape of the existing `app/Exceptions/Domain/InvalidPin.php` if it differs — every M3 exception in later tasks copies this file's skeleton.

```php
<?php
// app/Http/Middleware/EnsureIdempotency.php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\IdempotencyKeyReused;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay protection for mutations. The subtlety: the key and the work it guards must
 * commit together or not at all, so THIS middleware opens the transaction and the
 * action's own DB::transaction() nests inside it as a savepoint.
 * See docs/04-backend-conventions.md ("Two subtleties").
 */
final class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return $next($request);
        }

        $hash = hash('sha256', $request->method().$request->path().$request->getContent());

        return DB::transaction(function () use ($key, $hash, $request, $next): Response {
            $seen = IdempotencyKey::whereKey($key)->lockForUpdate()->first();

            if ($seen !== null) {
                if (! hash_equals($seen->request_hash, $hash)) {
                    throw new IdempotencyKeyReused($key);
                }

                return response()->json($seen->response_body, $seen->response_code);
            }

            $response = $next($request);   // the action's DB::transaction() nests here

            // Only success earns a key. A 409 insufficient_stock must stay retryable —
            // the stock might arrive. Failed work rolled back to its savepoint above.
            if ($response->isSuccessful()) {
                IdempotencyKey::create([
                    'key' => $key,
                    'request_hash' => $hash,
                    'response_code' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                    'created_at' => now(),
                ]);
            }

            return $response;
        });
    }
}
```

In `bootstrap/app.php`, add to the existing alias array:

```php
'idempotent' => \App\Http\Middleware\EnsureIdempotency::class,
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Http/IdempotencyTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app backend/bootstrap/app.php backend/tests
git commit -m "M3: idempotency middleware — key and work commit together"
```

---

### Task 3: PriceResolver

**Files:**
- Create: `backend/app/Domain/Pricing/PriceResolver.php`
- Test: `backend/tests/Feature/Pricing/PriceResolverTest.php`

**Interfaces:**
- Consumes: `ProductVariant` model, `variant_location_prices` table, `Money`.
- Produces: `PriceResolver::for(ProductVariant $variant, string $locationId): Money` — location override, else base price. The ONE place price resolution lives.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pricing/PriceResolverTest.php
declare(strict_types=1);

use App\Domain\Pricing\PriceResolver;
use App\Models\Location;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

it('uses the base price when the location has no override', function (): void {
    $variant = ProductVariant::factory()->create(['price_cents' => 1999]);
    $location = Location::factory()->create();

    expect(app(PriceResolver::class)->for($variant, $location->id)->cents)->toBe(1999);
});

it('prefers the location override', function (): void {
    $variant = ProductVariant::factory()->create(['price_cents' => 1999]);
    $airport = Location::factory()->create();
    DB::table('variant_location_prices')->insert([
        'variant_id' => $variant->id,
        'location_id' => $airport->id,
        'price_cents' => 2499,
    ]);

    expect(app(PriceResolver::class)->for($variant, $airport->id)->cents)->toBe(2499);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Pricing/PriceResolverTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
// app/Domain/Pricing/PriceResolver.php
declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money\Money;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Location override, else the variant's base price. Pricing resolution lives here and
 * nowhere else — the register never implements it. See docs/02-data-model.md.
 */
final class PriceResolver
{
    public function for(ProductVariant $variant, string $locationId): Money
    {
        $override = DB::table('variant_location_prices')
            ->where('variant_id', $variant->id)
            ->where('location_id', $locationId)
            ->value('price_cents');

        return Money::fromCents($override !== null ? (int) $override : $variant->price_cents);
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Pricing/PriceResolverTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Domain/Pricing backend/tests
git commit -m "M3: PriceResolver — location override else base"
```

---

### Task 4: StockLedger

**Files:**
- Create: `backend/app/Domain/Stock/StockLedger.php`, `backend/app/Exceptions/Domain/InsufficientStock.php`
- Test: `backend/tests/Feature/Stock/StockLedgerTest.php`, `backend/tests/Feature/Stock/ConcurrentSaleTest.php`

**Interfaces:**
- Consumes: `stock_movements`/`stock_levels` tables, `Quantity`.
- Produces:
  - `StockLedger::sell(string $variantId, string $locationId, Quantity $qty, string $refType, string $refId, ?string $userId): void` — locks the level row (`FOR UPDATE`), throws `InsufficientStock` (409) on oversell, writes movement + level in the caller's transaction. Throws `LogicException` if called outside a transaction.
  - `StockLedger::receive(string $variantId, string $locationId, Quantity $qty, ?string $userId = null, ?string $note = null): void` — positive movement, upserts the level row (used by seeder now, receiving in M4).

- [ ] **Step 1: Write the failing ledger test**

```php
<?php
// tests/Feature/Stock/StockLedgerTest.php
declare(strict_types=1);

use App\Domain\Money\Quantity;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\InsufficientStock;
use App\Models\Location;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->variant = ProductVariant::factory()->create();
    $this->location = Location::factory()->create();
    $this->ledger = app(StockLedger::class);
});

function stockLevel(string $variantId, string $locationId): ?string
{
    return DB::table('stock_levels')
        ->where('variant_id', $variantId)->where('location_id', $locationId)
        ->value('qty');
}

it('receive creates the level row and the ledger entry', function (): void {
    DB::transaction(fn () => $this->ledger->receive($this->variant->id, $this->location->id, Quantity::fromString('20')));

    expect(stockLevel($this->variant->id, $this->location->id))->toBe('20.000');
    $this->assertDatabaseHas('stock_movements', [
        'variant_id' => $this->variant->id,
        'reason' => 'receive',
        'qty_delta' => '20.000',
    ]);
});

it('sell decrements the level and writes a negative movement referencing the line', function (): void {
    $ref = (string) Str::uuid7();
    DB::transaction(function () use ($ref): void {
        $this->ledger->receive($this->variant->id, $this->location->id, Quantity::fromString('5'));
        $this->ledger->sell($this->variant->id, $this->location->id, Quantity::fromString('1.500'), 'order_line', $ref, null);
    });

    expect(stockLevel($this->variant->id, $this->location->id))->toBe('3.500');
    $this->assertDatabaseHas('stock_movements', [
        'reason' => 'sale',
        'qty_delta' => '-1.500',
        'ref_type' => 'order_line',
        'ref_id' => $ref,
    ]);
});

it('refuses to oversell, with the shortfall in the exception', function (): void {
    DB::transaction(fn () => $this->ledger->receive($this->variant->id, $this->location->id, Quantity::fromString('2')));

    expect(fn () => DB::transaction(fn () => $this->ledger->sell(
        $this->variant->id, $this->location->id, Quantity::fromString('3'), 'order_line', (string) Str::uuid7(), null,
    )))->toThrow(InsufficientStock::class);

    expect(stockLevel($this->variant->id, $this->location->id))->toBe('2.000');
});

it('treats a missing level row as zero stock', function (): void {
    expect(fn () => DB::transaction(fn () => $this->ledger->sell(
        $this->variant->id, $this->location->id, Quantity::fromString('1'), 'order_line', (string) Str::uuid7(), null,
    )))->toThrow(InsufficientStock::class);
});

```

(The `assertInTransaction` guard cannot get a direct test under `RefreshDatabase` — the wrapper transaction is always open. Every other test exercises the happy path through `DB::transaction`; no skipped placeholder test.)

- [ ] **Step 2: Write the failing concurrency test**

This is the "two cashiers sell the last unit" invariant from `docs/01-architecture.md`. It cannot run under `RefreshDatabase` (a second connection can't see uncommitted rows), so it uses two raw PDO connections against `pos_test` with manual cleanup, and deliberately repeats the ledger's SQL — a single-process Laravel test would pass with or without `FOR UPDATE`, which is worse than no test.

```php
<?php
// tests/Feature/Stock/ConcurrentSaleTest.php
declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

// No RefreshDatabase: both PDO connections must see committed rows.
pest()->extend(Tests\TestCase::class);

function rawPdo(): PDO
{
    $cfg = config('database.connections.pgsql');
    $pdo = new PDO(
        "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}",
        $cfg['username'], $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    return $pdo;
}

it('serializes concurrent sellers of the last unit with FOR UPDATE', function (): void {
    $a = rawPdo();
    $b = rawPdo();

    // Committed fixture rows (cleaned up below).
    $ids = [];
    foreach (['location' => "insert into locations (name, code, timezone) values ('Ctest', 'CT-'||substr(md5(random()::text),1,6), 'UTC') returning id",
              'product' => "insert into products (name) values ('Ctest') returning id"] as $k => $sql) {
        $ids[$k] = $a->query($sql)->fetchColumn();
    }
    $ids['variant'] = $a->query("insert into product_variants (product_id, name, sku, price_cents) values ('{$ids['product']}', 'Default', 'CTEST-'||substr(md5(random()::text),1,6), 100) returning id")->fetchColumn();
    $a->exec("insert into stock_levels (variant_id, location_id, qty) values ('{$ids['variant']}', '{$ids['location']}', 1)");

    try {
        // Cashier A locks the level row mid-sale.
        $a->exec('begin');
        $qtyA = $a->query("select qty from stock_levels where variant_id = '{$ids['variant']}' and location_id = '{$ids['location']}' for update")->fetchColumn();
        expect((float) $qtyA)->toBe(1.0);

        // Cashier B cannot even read the row for update while A holds it.
        $b->exec('begin');
        $blocked = false;
        try {
            $b->query("select qty from stock_levels where variant_id = '{$ids['variant']}' and location_id = '{$ids['location']}' for update nowait");
        } catch (PDOException $e) {
            $blocked = true;   // 55P03 lock_not_available
        }
        $b->exec('rollback');
        expect($blocked)->toBeTrue();

        // A completes the sale and commits.
        $a->exec("update stock_levels set qty = qty - 1 where variant_id = '{$ids['variant']}' and location_id = '{$ids['location']}'");
        $a->exec('commit');

        // B retries: sees 0, must refuse — one winner, one insufficient_stock.
        $qtyB = $b->query("select qty from stock_levels where variant_id = '{$ids['variant']}' and location_id = '{$ids['location']}' for update")->fetchColumn();
        expect((float) $qtyB)->toBe(0.0);
    } finally {
        foreach ([$a, $b] as $pdo) {
            try { $pdo->exec('rollback'); } catch (Throwable) {}
        }
        $a->exec("delete from stock_levels where variant_id = '{$ids['variant']}'");
        $a->exec("delete from product_variants where id = '{$ids['variant']}'");
        $a->exec("delete from products where id = '{$ids['product']}'");
        $a->exec("delete from locations where id = '{$ids['location']}'");
    }
});
```

If `pest()->extend()` inside a test file conflicts with the `Pest.php` `in('Feature')` binding, move this file to `tests/Feature/Stock/` exclusion or wrap with `uses(Tests\TestCase::class)->without(RefreshDatabase::class)` — whichever Pest 4 supports; the requirement is simply: TestCase booted, no wrapping transaction.

- [ ] **Step 3: Run both to verify they fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Stock/`
Expected: FAIL — `App\Domain\Stock\StockLedger` not found (ledger test); concurrency test passes already at the SQL level or fails on fixtures — fix fixtures until its assertions run, since it tests Postgres behaviour the ledger relies on.

- [ ] **Step 4: Implement**

```php
<?php
// app/Exceptions/Domain/InsufficientStock.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

final class InsufficientStock extends DomainException
{
    public function __construct(
        private readonly string $variantId,
        private readonly string $requested,
        private readonly string $available,
    ) {
        parent::__construct("Only {$available} units remain.");
    }

    public function errorCode(): string
    {
        return 'insufficient_stock';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return [
            'variant_id' => $this->variantId,
            'requested' => $this->requested,
            'available' => $this->available,
        ];
    }
}
```

```php
<?php
// app/Domain/Stock/StockLedger.php
declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Money\Quantity;
use App\Exceptions\Domain\InsufficientStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Stock is a ledger, not a number. Every change is an immutable stock_movements row
 * plus an update to the cached stock_levels row, in the SAME transaction — the
 * invariant is stock_levels.qty = sum(stock_movements.qty_delta).
 *
 * Selling locks the level row (SELECT ... FOR UPDATE): pessimistic on purpose, because
 * "never sell what we don't have" can't be promised by optimistic retry.
 * See docs/02-data-model.md (inventory).
 */
final class StockLedger
{
    public function sell(string $variantId, string $locationId, Quantity $qty, string $refType, string $refId, ?string $userId): void
    {
        $this->assertInTransaction();

        $level = DB::table('stock_levels')
            ->where('variant_id', $variantId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        $available = Quantity::fromString($level->qty ?? '0');

        if ($qty->greaterThan($available)) {
            throw new InsufficientStock($variantId, (string) $qty, (string) $available);
        }

        $this->move($variantId, $locationId, $qty->negated(), 'sale', $refType, $refId, $userId);
    }

    /** Deliveries and seed data. Creates the level row if it doesn't exist yet. */
    public function receive(string $variantId, string $locationId, Quantity $qty, ?string $userId = null, ?string $note = null): void
    {
        $this->assertInTransaction();
        $this->move($variantId, $locationId, $qty, 'receive', null, null, $userId, $note);
    }

    private function move(string $variantId, string $locationId, Quantity $delta, string $reason, ?string $refType, ?string $refId, ?string $userId, ?string $note = null): void
    {
        // Ledger row first, then the cache it derives.
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid7(),
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'qty_delta' => (string) $delta,
            'reason' => $reason,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'user_id' => $userId,
            'note' => $note,
            'created_at' => now(),
        ]);

        DB::statement(
            'insert into stock_levels (variant_id, location_id, qty, updated_at)
             values (?, ?, ?, now())
             on conflict (variant_id, location_id)
             do update set qty = stock_levels.qty + excluded.qty, updated_at = now()',
            [$variantId, $locationId, (string) $delta],
        );
    }

    private function assertInTransaction(): void
    {
        // The movement and the level must commit together; silently running outside a
        // transaction would make the invariant breakable by any caller mistake.
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockLedger must be called inside a transaction.');
        }
    }
}
```

Note: `Quantity::__toString()` renders 3 decimals (`'-1.500'`) — verify against `app/Domain/Money/Quantity.php:110` and adjust test expectations to its exact format.

- [ ] **Step 5: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Stock/`
Expected: PASS (ledger + concurrency).

- [ ] **Step 6: Commit**

```bash
git add backend/app backend/tests
git commit -m "M3: StockLedger — movement + level in one transaction, FOR UPDATE on sell"
```

---

### Task 5: OrderTotals

**Files:**
- Create: `backend/app/Domain/Pricing/OrderTotals.php`
- Test: `backend/tests/Feature/Pricing/OrderTotalsTest.php`

**Interfaces:**
- Consumes: `Order`/`OrderLine` models (Task 1), `Money`, `Quantity`, `App\Domain\Money\TaxRate`.
- Produces: `OrderTotals::recalculate(Order $order): void` — rewrites every non-voided line's `tax_cents` + `line_total_cents`, then the order's `subtotal_cents`/`discount_cents`/`tax_cents`/`total_cents`. Per-line rounding then sum. Exclusive: `total = subtotal + tax`; inclusive: `total = subtotal` (tax extracted, informational).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pricing/OrderTotalsTest.php
declare(strict_types=1);

use App\Domain\Pricing\OrderTotals;
use App\Models\Order;
use App\Models\ProductVariant;

function lineFor(Order $order, int $unitCents, int $rateMicros, string $qty): void
{
    $order->lines()->create([
        'variant_id' => ProductVariant::factory()->create()->id,
        'name_snapshot' => 'x',
        'sku_snapshot' => 'x',
        'unit_price_cents' => $unitCents,
        'tax_rate_micros' => $rateMicros,
        'qty' => $qty,
        'line_total_cents' => 0,
        'created_at' => now(),
    ]);
}

it('adds tax on top when prices exclude tax (US mode)', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    // 2 × $19.99 at NYC 8.875%: base 3998, tax = round(3998 × 0.08875) = 355
    lineFor($order, 1999, 88750, '2');

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->subtotal_cents)->toBe(3998)
        ->and($order->tax_cents)->toBe(355)
        ->and($order->total_cents)->toBe(4353)
        ->and($order->lines()->first()->line_total_cents)->toBe(3998)
        ->and($order->lines()->first()->tax_cents)->toBe(355);
});

it('extracts tax from the price when prices include tax (UK mode)', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => true]);
    // £3.50 latte at 20% VAT: tax = round(350 × 0.2/1.2) = 58; customer pays 350
    lineFor($order, 350, 200_000, '1');

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->subtotal_cents)->toBe(350)
        ->and($order->tax_cents)->toBe(58)
        ->and($order->total_cents)->toBe(350);
});

it('rounds per line then sums — never sums then rounds', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    // Three lines of 333 at 10%: per-line tax round(33.3)=33 ×3 = 99.
    // Sum-then-round would give round(999 × 0.1) = 100 — visibly wrong on paper.
    foreach (range(1, 3) as $i) {
        lineFor($order, 333, 100_000, '1');
    }

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->tax_cents)->toBe(99)->and($order->total_cents)->toBe(999 + 99);
});

it('handles fractional quantities through the one rounding primitive', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    // 0.500 kg × $24.00 = 1200; 20% tax = 240
    lineFor($order, 2400, 200_000, '0.500');

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->subtotal_cents)->toBe(1200)->and($order->tax_cents)->toBe(240);
});

it('ignores voided lines', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    lineFor($order, 1000, 0, '1');
    $order->lines()->first()->forceFill(['voided_at' => now()])->save();

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->subtotal_cents)->toBe(0)->and($order->total_cents)->toBe(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Pricing/OrderTotalsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
// app/Domain/Pricing/OrderTotals.php
declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money\Money;
use App\Domain\Money\Quantity;
use App\Domain\Money\TaxRate;
use App\Models\Order;

/**
 * The one place order money is computed. Recalculates every non-voided line, then the
 * order — per-line rounding then sum, so the receipt adds up in front of a customer
 * checking it with their phone. See docs/01-architecture.md (rounding).
 */
final class OrderTotals
{
    public function recalculate(Order $order): void
    {
        $lines = $order->lines()->whereNull('voided_at')->get();

        $subtotal = Money::zero();
        $tax = Money::zero();

        foreach ($lines as $line) {
            $base = Money::fromCents($line->unit_price_cents)
                ->multipliedByQuantity(Quantity::fromString($line->qty))
                ->plus(Money::fromCents($line->modifiers_total_cents))
                ->minus(Money::fromCents($line->discount_cents));

            $rate = TaxRate::fromMicros($line->tax_rate_micros);
            $lineTax = $order->prices_include_tax ? $rate->taxOnGross($base) : $rate->taxOnNet($base);

            $line->forceFill([
                'line_total_cents' => $base->cents,
                'tax_cents' => $lineTax->cents,
            ])->save();

            $subtotal = $subtotal->plus($base);
            $tax = $tax->plus($lineTax);
        }

        // Inclusive prices already contain the tax; exclusive adds it at the till.
        $total = $order->prices_include_tax ? $subtotal : $subtotal->plus($tax);

        $order->forceFill([
            'subtotal_cents' => $subtotal->cents,
            'discount_cents' => 0,   // ponytail: discounts land in M4; recalculate owns this column then
            'tax_cents' => $tax->cents,
            'total_cents' => $total->cents,
        ])->save();
    }
}
```

Verify the expected tax figures against the actual `TaxRate::taxOnNet`/`taxOnGross` implementations (M1-tested); if a fixture disagrees, trust the M1 semantics and fix the fixture.

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Pricing/OrderTotalsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Domain/Pricing backend/tests
git commit -m "M3: OrderTotals — per-line rounding, inclusive and exclusive tax"
```

---

### Task 6: OrderNumbers

**Files:**
- Create: `backend/app/Domain/Orders/OrderNumbers.php`
- Test: `backend/tests/Feature/Orders/OrderNumbersTest.php`

**Interfaces:**
- Consumes: `order_counters` table, `Location` model, `config('pos.orders.number_format')` (`'{location}-{date}-{seq}'`).
- Produces: `OrderNumbers::next(Location $location, string $businessDate): string` → `'DT-20260716-0001'`. Atomic upsert — no read-modify-write race, no gaps on rollback beyond the transaction that owns it.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Orders/OrderNumbersTest.php
declare(strict_types=1);

use App\Domain\Orders\OrderNumbers;
use App\Models\Location;

it('formats and increments per location per day', function (): void {
    $dt = Location::factory()->create(['code' => 'DT']);
    $numbers = app(OrderNumbers::class);

    expect($numbers->next($dt, '2026-07-16'))->toBe('DT-20260716-0001')
        ->and($numbers->next($dt, '2026-07-16'))->toBe('DT-20260716-0002');
});

it('resets per day and per location', function (): void {
    $dt = Location::factory()->create(['code' => 'DT']);
    $ldn = Location::factory()->create(['code' => 'LDN']);
    $numbers = app(OrderNumbers::class);

    $numbers->next($dt, '2026-07-16');

    expect($numbers->next($dt, '2026-07-17'))->toBe('DT-20260717-0001')
        ->and($numbers->next($ldn, '2026-07-16'))->toBe('LDN-20260716-0001');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Orders/OrderNumbersTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
// app/Domain/Orders/OrderNumbers.php
declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Human-facing order numbers: per location, per business day, gapless under
 * concurrency. A Postgres sequence won't do — sequences don't reset per day per
 * location and leak gaps on rollback, and an auditor reading a receipt book with
 * holes asks questions. See docs/02-data-model.md (order numbers).
 */
final class OrderNumbers
{
    public function next(Location $location, string $businessDate): string
    {
        $seq = DB::selectOne(
            'insert into order_counters (location_id, business_date, next_val)
             values (?, ?, 2)
             on conflict (location_id, business_date)
               do update set next_val = order_counters.next_val + 1
             returning next_val - 1 as seq',
            [$location->id, $businessDate],
        )->seq;

        return str_replace(
            ['{location}', '{date}', '{seq}'],
            [$location->code, str_replace('-', '', $businessDate), str_pad((string) $seq, 4, '0', STR_PAD_LEFT)],
            config('pos.orders.number_format'),
        );
    }
}
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Orders/OrderNumbersTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Domain/Orders backend/tests
git commit -m "M3: gapless per-location per-day order numbers"
```

---

### Task 7: Shifts — open, current, close

**Files:**
- Create: `backend/app/Actions/Shifts/OpenShift.php`, `OpenShiftInput.php`, `GetCurrentShift.php`, `CurrentShiftStatus.php`, `CloseShift.php`, `CloseShiftInput.php`
- Create: `backend/app/Domain/Shifts/ShiftTotals.php`
- Create: `backend/app/Exceptions/Domain/ShiftAlreadyOpen.php`, `ShiftAlreadyClosed.php`, `ShiftHasOpenOrders.php`, `NoOpenShift.php`
- Create: `backend/app/Http/Controllers/Shifts/OpenShiftController.php`, `CurrentShiftController.php`, `CloseShiftController.php`
- Create: `backend/app/Http/Requests/Shifts/OpenShiftRequest.php`, `CloseShiftRequest.php`
- Create: `backend/app/Http/Resources/ShiftResource.php`, `CurrentShiftResource.php`, `CloseShiftResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Shifts/OpenShiftTest.php`, `CurrentShiftTest.php`, `CloseShiftTest.php`

**Interfaces:**
- Consumes: `Shift`/`Order` models, `AuditLogger::record(string $action, Model|string $entity, ?string $actorId, array $payload = [], ?string $registerId = null, ?string $ip = null)`, Pest helpers from Task 1, `idempotent` middleware from Task 2.
- Produces:
  - `OpenShift::execute(OpenShiftInput{registerId, openingFloatCents, actorId}): Shift` — throws `ShiftAlreadyOpen` (409) via the partial-unique-index violation, no pre-check.
  - `GetCurrentShift::execute(string $registerId): CurrentShiftStatus{shift, expectedCashCents, salesSummary}` — `ModelNotFoundException` (→404) when none open.
  - `CloseShift::execute(CloseShiftInput{shiftId, registerId, countedCashCents, note, actorId}): Shift` — 409 `shift_already_closed` / `shift_has_open_orders`; records variance, never blocks on it; revokes the register's staff sessions ("session ends at shift close").
  - `ShiftTotals::expectedCashCents(Shift): int` = float + captured cash payments − cash refunds + (paid_ins − payouts − drops). `ShiftTotals::salesSummary(Shift): array{orders_closed:int, total_cents:int, cash_cents:int}`.
  - `NoOpenShift` (409 `no_open_shift`) — also thrown by Tasks 8/9.
  - Routes: `POST /api/v1/shifts/open`, `GET /api/v1/shifts/current`, `POST /api/v1/shifts/{shift}/close` (device+staff; close also `idempotent`).

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Shifts/OpenShiftTest.php
declare(strict_types=1);

use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\ShiftAlreadyOpen;
use App\Models\User;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
});

it('opens a shift with a float and audits it', function (): void {
    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $this->register->id,
        openingFloatCents: 20000,
        actorId: $this->cashier->id,
    ));

    expect($shift->opening_float_cents)->toBe(20000)->and($shift->closed_at)->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'shift.open', 'entity_id' => $shift->id]);
});

it('refuses a second open shift on the same register', function (): void {
    $input = new OpenShiftInput($this->register->id, 20000, $this->cashier->id);
    app(OpenShift::class)->execute($input);

    expect(fn () => app(OpenShift::class)->execute($input))->toThrow(ShiftAlreadyOpen::class);
});

it('opens over HTTP with the right envelope', function (): void {
    $this->postJson('/api/v1/shifts/open', ['opening_float_cents' => 15000], staffHeaders($this->register, $this->cashier))
        ->assertCreated()
        ->assertJsonPath('data.opening_float_cents', 15000)
        ->assertJsonPath('data.register_id', $this->register->id);
});
```

```php
<?php
// tests/Feature/Shifts/CurrentShiftTest.php
declare(strict_types=1);

use App\Actions\Shifts\GetCurrentShift;
use App\Domain\Rbac\Roles;
use App\Models\Shift;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
});

it('404s when no shift is open', function (): void {
    expect(fn () => app(GetCurrentShift::class)->execute($this->register->id))
        ->toThrow(ModelNotFoundException::class);
});

it('returns the open shift with expected cash and a sales summary', function (): void {
    Shift::factory()->create(['register_id' => $this->register->id, 'opening_float_cents' => 10000]);

    $this->getJson('/api/v1/shifts/current', staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.expected_cash_cents', 10000)
        ->assertJsonPath('data.sales_summary.orders_closed', 0)
        ->assertJsonPath('data.shift.opening_float_cents', 10000);
});
```

```php
<?php
// tests/Feature/Shifts/CloseShiftTest.php
declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\ShiftAlreadyClosed;
use App\Exceptions\Domain\ShiftHasOpenOrders;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->shift = Shift::factory()->create([
        'register_id' => $this->register->id,
        'opened_by' => $this->cashier->id,
        'opening_float_cents' => 10000,
    ]);
});

it('computes variance = counted − (float + cash sales)', function (): void {
    $order = Order::factory()->forRegister($this->register)->create([
        'shift_id' => $this->shift->id, 'status' => 'closed', 'total_cents' => 4353, 'paid_cents' => 4353,
    ]);
    Payment::create([
        'order_id' => $order->id, 'shift_id' => $this->shift->id, 'driver' => 'cash',
        'status' => 'captured', 'amount_cents' => 4353, 'tendered_cents' => 5000,
        'change_cents' => 647, 'user_id' => $this->cashier->id,
        'created_at' => now(), 'captured_at' => now(),
    ]);

    $closed = app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $this->shift->id, registerId: $this->register->id,
        countedCashCents: 14300, note: null, actorId: $this->cashier->id,
    ));

    // expected = 10000 float + 4353 cash sales = 14353; counted 14300 → variance −53
    expect($closed->expected_cash_cents)->toBe(14353)
        ->and($closed->variance_cents)->toBe(-53)
        ->and($closed->closed_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'shift.close', 'entity_id' => $closed->id]);
});

it('refuses to close with open orders, listing them', function (): void {
    Order::factory()->forRegister($this->register)->create(['shift_id' => $this->shift->id, 'status' => 'open']);

    expect(fn () => app(CloseShift::class)->execute(new CloseShiftInput(
        $this->shift->id, $this->register->id, 10000, null, $this->cashier->id,
    )))->toThrow(ShiftHasOpenOrders::class);
});

it('refuses a double close', function (): void {
    $input = new CloseShiftInput($this->shift->id, $this->register->id, 10000, null, $this->cashier->id);
    app(CloseShift::class)->execute($input);

    expect(fn () => app(CloseShift::class)->execute($input))->toThrow(ShiftAlreadyClosed::class);
});

it('flags variance beyond the threshold for approval, but still closes', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['Idempotency-Key' => (string) Str::uuid()];

    // threshold is 500 (config/pos.php); short by 600
    $this->postJson("/api/v1/shifts/{$this->shift->id}/close", ['counted_cash_cents' => 9400], $headers)
        ->assertOk()
        ->assertJsonPath('data.variance_cents', -600)
        ->assertJsonPath('data.requires_approval', true);

    expect(Shift::findOrFail($this->shift->id)->closed_at)->not->toBeNull();
});

it('requires an Idempotency-Key over HTTP', function (): void {
    $this->postJson("/api/v1/shifts/{$this->shift->id}/close", ['counted_cash_cents' => 10000], staffHeaders($this->register, $this->cashier))
        ->assertStatus(422);
});

it('ends the staff sessions on that register at close', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['Idempotency-Key' => (string) Str::uuid()];

    $this->postJson("/api/v1/shifts/{$this->shift->id}/close", ['counted_cash_cents' => 10000], $headers)->assertOk();

    // The same staff token is now dead; the device token still works.
    $this->getJson('/api/v1/shifts/current', $headers)->assertStatus(401);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Shifts/`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement exceptions**

All four copy the `DomainException` skeleton (see Task 2's `IdempotencyKeyReused` for the shape):

| Class | `errorCode()` | `httpStatus()` | ctor / `details()` |
| --- | --- | --- | --- |
| `ShiftAlreadyOpen` | `shift_already_open` | 409 | `(string $registerId)`; `['register_id' => ...]`; message "This register already has an open shift." |
| `ShiftAlreadyClosed` | `shift_already_closed` | 409 | `(string $shiftId)`; `['shift_id' => ...]`; message "This shift is already closed." |
| `ShiftHasOpenOrders` | `shift_has_open_orders` | 409 | `(string $shiftId, array $orders)` where `$orders` is `[['id' => ..., 'number' => ...], ...]`; `['shift_id' => ..., 'open_orders' => $orders]`; message "Close or transfer the open orders first." |
| `NoOpenShift` | `no_open_shift` | 409 | `(string $registerId)`; `['register_id' => ...]`; message "Open a shift before taking sales." |

- [ ] **Step 4: Implement ShiftTotals**

```php
<?php
// app/Domain/Shifts/ShiftTotals.php
declare(strict_types=1);

namespace App\Domain\Shifts;

use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Drawer math, shared by GetCurrentShift and CloseShift. Variance is computed at
 * close from the ledger tables, never kept as a running total.
 * See docs/02-data-model.md (cash accountability).
 */
final class ShiftTotals
{
    public function expectedCashCents(Shift $shift): int
    {
        $cashSales = (int) DB::table('payments')
            ->where('shift_id', $shift->id)
            ->where('driver', 'cash')
            ->where('status', 'captured')
            ->sum('amount_cents');

        // Zero rows until M4 ships refunds and cash movements; queried now so the
        // formula is already right the day they exist.
        $cashRefunds = (int) DB::table('refunds')
            ->where('shift_id', $shift->id)
            ->where('driver', 'cash')
            ->sum('amount_cents');

        $movements = (int) DB::table('cash_movements')
            ->where('shift_id', $shift->id)
            ->selectRaw("coalesce(sum(case when kind = 'paid_in' then amount_cents else -amount_cents end), 0) as net")
            ->value('net');

        return $shift->opening_float_cents + $cashSales - $cashRefunds + $movements;
    }

    /** @return array{orders_closed: int, total_cents: int, cash_cents: int} */
    public function salesSummary(Shift $shift): array
    {
        $byDriver = DB::table('payments')
            ->where('shift_id', $shift->id)
            ->where('status', 'captured')
            ->groupBy('driver')
            ->selectRaw('driver, sum(amount_cents) as cents')
            ->pluck('cents', 'driver');

        return [
            'orders_closed' => DB::table('orders')->where('shift_id', $shift->id)->where('status', 'closed')->count(),
            'total_cents' => (int) $byDriver->sum(),
            'cash_cents' => (int) ($byDriver['cash'] ?? 0),
        ];
    }
}
```

- [ ] **Step 5: Implement the actions**

```php
<?php
// app/Actions/Shifts/OpenShiftInput.php
declare(strict_types=1);

namespace App\Actions\Shifts;

final readonly class OpenShiftInput
{
    public function __construct(
        public string $registerId,
        public int $openingFloatCents,
        public string $actorId,
    ) {}
}
```

```php
<?php
// app/Actions/Shifts/OpenShift.php
declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\ShiftAlreadyOpen;
use App\Models\Shift;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a drawer session. "One open shift per register" is the partial unique index's
 * job — we race straight into the insert and translate the violation, because an
 * application pre-check would just be a second, raceable copy of the invariant.
 */
final class OpenShift
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(OpenShiftInput $in): Shift
    {
        try {
            return DB::transaction(function () use ($in): Shift {
                $shift = Shift::create([
                    'register_id' => $in->registerId,
                    'opened_by' => $in->actorId,
                    'opened_at' => now(),
                    'opening_float_cents' => $in->openingFloatCents,
                ]);

                $this->audit->record('shift.open', $shift, $in->actorId, [
                    'opening_float_cents' => $in->openingFloatCents,
                ], registerId: $in->registerId);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ShiftAlreadyOpen($in->registerId);
        }
    }
}
```

```php
<?php
// app/Actions/Shifts/CurrentShiftStatus.php
declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Models\Shift;

final readonly class CurrentShiftStatus
{
    /** @param array{orders_closed: int, total_cents: int, cash_cents: int} $salesSummary */
    public function __construct(
        public Shift $shift,
        public int $expectedCashCents,
        public array $salesSummary,
    ) {}
}
```

```php
<?php
// app/Actions/Shifts/GetCurrentShift.php
declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Domain\Shifts\ShiftTotals;
use App\Models\Shift;

final class GetCurrentShift
{
    public function __construct(private readonly ShiftTotals $totals) {}

    public function execute(string $registerId): CurrentShiftStatus
    {
        $shift = Shift::where('register_id', $registerId)->whereNull('closed_at')->firstOrFail();

        return new CurrentShiftStatus(
            shift: $shift,
            expectedCashCents: $this->totals->expectedCashCents($shift),
            salesSummary: $this->totals->salesSummary($shift),
        );
    }
}
```

```php
<?php
// app/Actions/Shifts/CloseShiftInput.php
declare(strict_types=1);

namespace App\Actions\Shifts;

final readonly class CloseShiftInput
{
    public function __construct(
        public string $shiftId,
        public string $registerId,
        public int $countedCashCents,
        public ?string $note,
        public string $actorId,
    ) {}
}
```

```php
<?php
// app/Actions/Shifts/CloseShift.php
declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shifts\ShiftTotals;
use App\Exceptions\Domain\ShiftAlreadyClosed;
use App\Exceptions\Domain\ShiftHasOpenOrders;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Counts the drawer. Variance is recorded, never blocked — a drawer that refuses to
 * close gets closed by unplugging the terminal, and then there's no data at all.
 * Approval beyond the threshold is an audit event (M4), not a gate.
 */
final class CloseShift
{
    public function __construct(
        private readonly ShiftTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CloseShiftInput $in): Shift
    {
        return DB::transaction(function () use ($in): Shift {
            $shift = Shift::whereKey($in->shiftId)
                ->where('register_id', $in->registerId)   // another register's shift is a 404, not a 403 leak
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->closed_at !== null) {
                throw new ShiftAlreadyClosed($shift->id);
            }

            $open = $shift->orders()->where('status', 'open')->get(['id', 'number']);
            if ($open->isNotEmpty()) {
                // A tab cannot outlive the drawer that's accountable for it.
                throw new ShiftHasOpenOrders($shift->id, $open->map->only(['id', 'number'])->all());
            }

            $expected = $this->totals->expectedCashCents($shift);

            $shift->forceFill([
                'closed_by' => $in->actorId,
                'closed_at' => now(),
                'counted_cash_cents' => $in->countedCashCents,
                'expected_cash_cents' => $expected,
                'variance_cents' => $in->countedCashCents - $expected,
                'close_note' => $in->note,
            ])->save();

            // Staff sessions end at shift close (docs/01-architecture.md). Matches the
            // ability string StaffLogin issues; device tokens don't carry it.
            PersonalAccessToken::query()
                ->where('abilities', 'like', '%register:'.$shift->register_id.'%')
                ->delete();

            $this->audit->record('shift.close', $shift, $in->actorId, [
                'counted_cash_cents' => $in->countedCashCents,
                'expected_cash_cents' => $expected,
                'variance_cents' => $shift->variance_cents,
            ], registerId: $in->registerId);

            return $shift;
        });
    }
}
```

- [ ] **Step 6: Implement HTTP layer**

```php
<?php
// app/Http/Requests/Shifts/OpenShiftRequest.php
declare(strict_types=1);

namespace App\Http\Requests\Shifts;

use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::SHIFT_OPEN);
    }

    public function rules(): array
    {
        return [
            'opening_float_cents' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): OpenShiftInput
    {
        return new OpenShiftInput(
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            openingFloatCents: $this->integer('opening_float_cents'),
            actorId: $this->user()->id,
        );
    }
}
```

```php
<?php
// app/Http/Requests/Shifts/CloseShiftRequest.php
declare(strict_types=1);

namespace App\Http\Requests\Shifts;

use App\Actions\Shifts\CloseShiftInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::SHIFT_CLOSE);
    }

    protected function prepareForValidation(): void
    {
        // The middleware treats the key as optional; this endpoint requires it.
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'counted_cash_cents' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function toInput(): CloseShiftInput
    {
        return new CloseShiftInput(
            shiftId: (string) $this->route('shift'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            countedCashCents: $this->integer('counted_cash_cents'),
            note: $this->string('note')->toString() ?: null,
            actorId: $this->user()->id,
        );
    }
}
```

Controllers (all three follow the house 3-line shape):

```php
<?php
// app/Http/Controllers/Shifts/OpenShiftController.php
declare(strict_types=1);

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\OpenShift;
use App\Http\Requests\Shifts\OpenShiftRequest;
use App\Http\Resources\ShiftResource;
use Illuminate\Http\JsonResponse;

final class OpenShiftController
{
    public function __invoke(OpenShiftRequest $request, OpenShift $action): JsonResponse
    {
        return (new ShiftResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
```

```php
<?php
// app/Http/Controllers/Shifts/CurrentShiftController.php
declare(strict_types=1);

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\GetCurrentShift;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Resources\CurrentShiftResource;
use Illuminate\Http\Request;

final class CurrentShiftController
{
    public function __invoke(Request $request, GetCurrentShift $action): CurrentShiftResource
    {
        return new CurrentShiftResource($action->execute($request->attributes->get(EnsureDeviceToken::REGISTER)->id));
    }
}
```

```php
<?php
// app/Http/Controllers/Shifts/CloseShiftController.php
declare(strict_types=1);

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\CloseShift;
use App\Http\Requests\Shifts\CloseShiftRequest;
use App\Http\Resources\CloseShiftResource;

final class CloseShiftController
{
    public function __invoke(CloseShiftRequest $request, CloseShift $action): CloseShiftResource
    {
        return new CloseShiftResource($action->execute($request->toInput()));
    }
}
```

Resources:

```php
<?php
// app/Http/Resources/ShiftResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'register_id' => $this->register_id,
            'opened_by' => $this->opened_by,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opening_float_cents' => $this->opening_float_cents,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'counted_cash_cents' => $this->counted_cash_cents,
            'expected_cash_cents' => $this->expected_cash_cents,
            'variance_cents' => $this->variance_cents,
        ];
    }
}
```

```php
<?php
// app/Http/Resources/CurrentShiftResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps CurrentShiftStatus (Actions/Shifts). */
final class CurrentShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'shift' => new ShiftResource($this->shift),
            'expected_cash_cents' => $this->expectedCashCents,
            'sales_summary' => $this->salesSummary,
        ];
    }
}
```

```php
<?php
// app/Http/Resources/CloseShiftResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The close response: docs/03-api.md promises the reconciliation numbers top-level. */
final class CloseShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'shift' => new ShiftResource($this->resource),
            'expected_cash_cents' => $this->expected_cash_cents,
            'variance_cents' => $this->variance_cents,
            'requires_approval' => abs($this->variance_cents) > config('pos.shifts.variance_approval_threshold_cents'),
        ];
    }
}
```

Note: `CloseShiftTest` asserts `data.variance_cents` and `data.requires_approval` — matches this resource. The OpenShift HTTP test asserts `data.opening_float_cents` on `ShiftResource` — also matches.

Routes — inside the existing `Route::middleware('staff')` group in `routes/api.php`:

```php
Route::post('/shifts/open', OpenShiftController::class)->name('shifts.open');
Route::get('/shifts/current', CurrentShiftController::class)->name('shifts.current');
Route::post('/shifts/{shift}/close', CloseShiftController::class)
    ->middleware('idempotent')
    ->name('shifts.close');
```

- [ ] **Step 7: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Shifts/ && ./vendor/bin/pest tests/Arch`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/app backend/routes backend/tests
git commit -m "M3: shifts — open with float, current with drawer math, close with variance"
```

---

### Task 8: Orders — open and add line

**Files:**
- Create: `backend/app/Actions/Orders/OpenOrder.php`, `OpenOrderInput.php`, `AddLineToOrder.php`, `AddLineInput.php`
- Create: `backend/app/Exceptions/Domain/OrderClosed.php`, `OrderVersionConflict.php`
- Create: `backend/app/Http/Controllers/Orders/OpenOrderController.php`, `AddLineController.php`
- Create: `backend/app/Http/Requests/Orders/OpenOrderRequest.php`, `AddLineRequest.php`
- Create: `backend/app/Http/Resources/OrderResource.php`, `OrderLineResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Orders/OpenOrderTest.php`, `AddLineTest.php`

**Interfaces:**
- Consumes: `OrderNumbers` (Task 6), `PriceResolver` (Task 3), `StockLedger` (Task 4), `OrderTotals` (Task 5), `NoOpenShift` (Task 7), `AuditLogger`, `Register::location` relation (verify it exists on `app/Models/Register.php`; add a `belongsTo` if M2 didn't).
- Produces:
  - `OpenOrder::execute(OpenOrderInput{registerId, actorId, tableRef, customerId}): Order` — snapshots `prices_include_tax`, computes `business_date` in the location's timezone, assigns `number`.
  - `AddLineToOrder::execute(AddLineInput{orderId, variantId, qty, expectedVersion, actorId}): Order` — the worked example from `docs/04-backend-conventions.md`, minus modifiers (M5). Lock → status check → version check → snapshot → conditional stock decrement → recalc → version bump → audit, all in one transaction. Returns order with `lines` loaded.
  - `OrderClosed` (409 `order_closed`), `OrderVersionConflict` (409 `order_version_conflict`, current state in `details`).
  - Routes: `POST /api/v1/orders`, `POST /api/v1/orders/{order}/lines` (device+staff; lines also `idempotent`).
  - `OrderResource` — the shape every later order mutation returns (docs/04 conventions, verbatim fields incl. `version`, `lines` via `whenLoaded`).

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Orders/OpenOrderTest.php
declare(strict_types=1);

use App\Actions\Orders\OpenOrder;
use App\Actions\Orders\OpenOrderInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\NoOpenShift;
use App\Models\OrderStatus;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'DT', 'timezone' => 'America/New_York', 'prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
});

it('refuses to open an order without an open shift', function (): void {
    expect(fn () => app(OpenOrder::class)->execute(new OpenOrderInput(
        registerId: $this->register->id, actorId: $this->cashier->id, tableRef: null, customerId: null,
    )))->toThrow(NoOpenShift::class);
});

it('opens with number, snapshot, business date, and version 0', function (): void {
    $shift = Shift::factory()->create(['register_id' => $this->register->id]);

    $order = app(OpenOrder::class)->execute(new OpenOrderInput(
        registerId: $this->register->id, actorId: $this->cashier->id, tableRef: '12', customerId: null,
    ));

    $localDate = now('America/New_York')->toDateString();

    expect($order->number)->toBe('DT-'.str_replace('-', '', $localDate).'-0001')
        ->and($order->status)->toBe(OrderStatus::Open)
        ->and($order->version)->toBe(0)
        ->and($order->shift_id)->toBe($shift->id)
        ->and($order->prices_include_tax)->toBeFalse()
        ->and($order->business_date)->toBe($localDate)
        ->and($order->table_ref)->toBe('12');
});

it('opens over HTTP with the envelope', function (): void {
    Shift::factory()->create(['register_id' => $this->register->id]);

    $this->postJson('/api/v1/orders', [], staffHeaders($this->register, $this->cashier))
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.version', 0)
        ->assertJsonPath('data.total_cents', 0);
});
```

```php
<?php
// tests/Feature/Orders/AddLineTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\AddLineInput;
use App\Domain\Money\Quantity;
use App\Domain\Rbac\Roles;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\InsufficientStock;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);

    $nyc = TaxRate::factory()->create(['rate_micros' => 88750]);
    $this->variant = ProductVariant::factory()->create([
        'sku' => 'TSHIRT-BLUE-L', 'price_cents' => 1999, 'tax_rate_id' => $nyc->id, 'track_inventory' => true,
    ]);
    DB::transaction(fn () => app(StockLedger::class)->receive($this->variant->id, $this->location->id, Quantity::fromString('10')));
});

function addLine(object $t, string $qty = '1', ?int $version = null): Order
{
    return app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $t->order->id,
        variantId: $t->variant->id,
        qty: $qty,
        expectedVersion: $version ?? Order::findOrFail($t->order->id)->version,
        actorId: $t->cashier->id,
    ));
}

it('snapshots the line, decrements stock, recomputes totals, bumps version — atomically', function (): void {
    $order = addLine($this, '2');

    $line = $order->lines->first();
    expect($line->name_snapshot)->not->toBe('')
        ->and($line->sku_snapshot)->toBe('TSHIRT-BLUE-L')
        ->and($line->unit_price_cents)->toBe(1999)
        ->and($line->tax_rate_micros)->toBe(88750)
        ->and($line->qty)->toBe('2.000')
        ->and($order->subtotal_cents)->toBe(3998)
        ->and($order->tax_cents)->toBe(355)
        ->and($order->total_cents)->toBe(4353)
        ->and($order->version)->toBe(1);

    expect(DB::table('stock_levels')->where('variant_id', $this->variant->id)->value('qty'))->toBe('8.000');
    $this->assertDatabaseHas('stock_movements', ['ref_type' => 'order_line', 'ref_id' => $line->id, 'reason' => 'sale']);
    $this->assertDatabaseHas('audit_log', ['action' => 'order.line.add', 'entity_id' => $line->id]);
});

it('uses the location override price, not the base', function (): void {
    DB::table('variant_location_prices')->insert([
        'variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'price_cents' => 2499,
    ]);

    expect(addLine($this)->lines->first()->unit_price_cents)->toBe(2499);
});

it('a receipt-bound snapshot survives a later catalog reprice', function (): void {
    $order = addLine($this);
    $this->variant->update(['price_cents' => 9999]);

    expect($order->lines()->first()->unit_price_cents)->toBe(1999);
});

it('rejects a stale version with the current one in details', function (): void {
    addLine($this);   // version now 1

    expect(fn () => addLine($this, '1', version: 0))->toThrow(OrderVersionConflict::class);
});

it('rejects lines on a closed order', function (): void {
    $this->order->forceFill(['status' => 'closed'])->save();

    expect(fn () => addLine($this))->toThrow(OrderClosed::class);
});

it('refuses to oversell and leaves NO orphan line behind', function (): void {
    expect(fn () => addLine($this, '11'))->toThrow(InsufficientStock::class);

    expect($this->order->lines()->count())->toBe(0)
        ->and(DB::table('stock_levels')->where('variant_id', $this->variant->id)->value('qty'))->toBe('10.000');
});

it('skips the stock lock for untracked variants', function (): void {
    $latte = ProductVariant::factory()->untracked()->create(['price_cents' => 350]);

    $order = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, variantId: $latte->id, qty: '1',
        expectedVersion: 0, actorId: $this->cashier->id,
    ));

    expect($order->lines->count())->toBe(1);
    $this->assertDatabaseMissing('stock_movements', ['variant_id' => $latte->id]);
});

it('adds a line over HTTP with If-Match and returns the whole order', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/lines", ['variant_id' => $this->variant->id, 'qty' => '1'], $headers)
        ->assertCreated()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.total_cents', 2176)   // 1999 + round(1999×0.08875)=177
        ->assertJsonPath('data.lines.0.sku', 'TSHIRT-BLUE-L');
});

it('rejects modifiers in M3', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/lines",
        ['variant_id' => $this->variant->id, 'qty' => '1', 'modifiers' => ['x']], $headers)
        ->assertStatus(422);
});
```

Note the `addLine(object $t, ...)` helper takes the Pest test case as a plain object — if name collisions arise with other Pest files, rename to `m3AddLine`.

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Orders/`
Expected: FAIL — classes not found (OrderNumbersTest from Task 6 still passes).

- [ ] **Step 3: Implement exceptions**

`DomainException` skeleton again:

| Class | `errorCode()` | `httpStatus()` | ctor / `details()` |
| --- | --- | --- | --- |
| `OrderClosed` | `order_closed` | 409 | `(string $orderId, string $status)`; `['order_id' => ..., 'status' => ...]`; message "This order is {$status} and cannot be changed." |
| `OrderVersionConflict` | `order_version_conflict` | 409 | `(string $orderId, int $expected, int $current)`; `['order_id' => ..., 'expected_version' => ..., 'current_version' => ...]`; message "The order changed since you read it. Refetch and retry." |

- [ ] **Step 4: Implement the actions**

```php
<?php
// app/Actions/Orders/OpenOrderInput.php
declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class OpenOrderInput
{
    public function __construct(
        public string $registerId,
        public string $actorId,
        public ?string $tableRef,
        public ?string $customerId,
    ) {}
}
```

```php
<?php
// app/Actions/Orders/OpenOrder.php
declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OrderNumbers;
use App\Exceptions\Domain\NoOpenShift;
use App\Models\Order;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Opens the lifecycle both retail and food service travel. Retail calls this
 * implicitly on first scan; food service names a table. Same row either way.
 * prices_include_tax is snapshotted here so an admin flipping the setting mid-shift
 * can't change the arithmetic of orders already in flight.
 */
final class OpenOrder
{
    public function __construct(
        private readonly OrderNumbers $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(OpenOrderInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $register = Register::with('location')->findOrFail($in->registerId);

            $shift = Shift::where('register_id', $register->id)->whereNull('closed_at')->first()
                ?? throw new NoOpenShift($register->id);

            $location = $register->location;
            // The local calendar day at the store, stored so every report groups by it.
            $businessDate = now($location->timezone)->toDateString();

            $order = Order::create([
                'number' => $this->numbers->next($location, $businessDate),
                'location_id' => $location->id,
                'register_id' => $register->id,
                'shift_id' => $shift->id,
                'business_date' => $businessDate,
                'opened_by' => $in->actorId,
                'customer_id' => $in->customerId,
                'table_ref' => $in->tableRef,
                'status' => 'open',
                'prices_include_tax' => $location->prices_include_tax,
                'opened_at' => now(),
            ]);

            $this->audit->record('order.open', $order, $in->actorId, registerId: $register->id);

            return $order;
        });
    }
}
```

```php
<?php
// app/Actions/Orders/AddLineInput.php
declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class AddLineInput
{
    public function __construct(
        public string $orderId,
        public string $variantId,
        public string $qty,            // numeric string; never float — see docs/03-api.md
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
```

```php
<?php
// app/Actions/Orders/AddLineToOrder.php
declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Quantity;
use App\Domain\Pricing\OrderTotals;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * The most demanding action in the system: resolve the location price, snapshot it,
 * lock and decrement stock, recompute totals, bump the version — atomically.
 * The row lock serializes concurrent writers; the version check rejects a stale
 * client. Both, deliberately. (Modifiers arrive in M5.)
 * See the worked example in docs/04-backend-conventions.md.
 */
final class AddLineToOrder
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly StockLedger $stock,
        private readonly OrderTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(AddLineInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = Order::whereKey($in->orderId)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Open) {
                throw new OrderClosed($order->id, $order->status->value);
            }

            // Inside the transaction, after the lock — never in the FormRequest.
            if ($order->version !== $in->expectedVersion) {
                throw new OrderVersionConflict($order->id, $in->expectedVersion, $order->version);
            }

            $variant = ProductVariant::query()->active()->with(['product', 'taxRate'])->findOrFail($in->variantId);
            $price = $this->prices->for($variant, $order->location_id);

            // The only moment the live catalog is consulted; the receipt reads these
            // snapshots forever after.
            $line = $order->lines()->create([
                'variant_id' => $variant->id,
                'name_snapshot' => $variant->displayName(),
                'sku_snapshot' => $variant->sku,
                'unit_price_cents' => $price->cents,
                'tax_rate_micros' => $variant->taxRate?->rate_micros ?? 0,
                'qty' => $in->qty,
                'line_total_cents' => 0,   // OrderTotals writes the real value below
                'position' => $order->lines()->count(),
                'created_at' => now(),
            ]);

            if ($variant->track_inventory) {
                $this->stock->sell(
                    $variant->id, $order->location_id, Quantity::fromString($in->qty),
                    refType: 'order_line', refId: $line->id, userId: $in->actorId,
                );
            }

            $this->totals->recalculate($order);
            $order->forceFill(['version' => $order->version + 1])->save();

            $this->audit->record('order.line.add', $line, $in->actorId, [
                'order_id' => $order->id, 'sku' => $line->sku_snapshot, 'qty' => $in->qty,
            ]);

            return $order->fresh(['lines']);
        });
    }
}
```

Check `app/Models/TaxRate.php` exposes `rate_micros` as int (M2 cast) and `ProductVariantFactory` has an `untracked()` state (seen in seeder usage — it exists).

- [ ] **Step 5: Implement HTTP layer**

```php
<?php
// app/Http/Requests/Orders/OpenOrderRequest.php
declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\OpenOrderInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class OpenOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_OPEN);
    }

    public function rules(): array
    {
        return [
            'table_ref' => ['nullable', 'string', 'max:20'],
            'customer_id' => ['nullable', 'uuid'],
        ];
    }

    public function toInput(): OpenOrderInput
    {
        return new OpenOrderInput(
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            actorId: $this->user()->id,
            tableRef: $this->string('table_ref')->toString() ?: null,
            customerId: $this->string('customer_id')->toString() ?: null,
        );
    }
}
```

```php
<?php
// app/Http/Requests/Orders/AddLineRequest.php
declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\AddLineInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class AddLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_LINE_ADD);
    }

    protected function prepareForValidation(): void
    {
        // Presence and well-formedness only; the compare happens inside the
        // transaction, after the lock (docs/04-backend-conventions.md).
        $this->merge(['if_match' => $this->header('If-Match')]);
    }

    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'uuid'],
            'qty' => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'modifiers' => ['prohibited'],   // ponytail: modifiers are M5; loud beats silently ignored
            'if_match' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): AddLineInput
    {
        return new AddLineInput(
            orderId: (string) $this->route('order'),
            variantId: $this->string('variant_id')->toString(),
            qty: $this->string('qty')->toString(),
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
```

```php
<?php
// app/Http/Controllers/Orders/OpenOrderController.php
declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\OpenOrder;
use App\Http\Requests\Orders\OpenOrderRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

final class OpenOrderController
{
    public function __invoke(OpenOrderRequest $request, OpenOrder $action): JsonResponse
    {
        return (new OrderResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
```

```php
<?php
// app/Http/Controllers/Orders/AddLineController.php
declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\AddLineToOrder;
use App\Http\Requests\Orders\AddLineRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

final class AddLineController
{
    public function __invoke(AddLineRequest $request, AddLineToOrder $action): JsonResponse
    {
        return (new OrderResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
```

```php
<?php
// app/Http/Resources/OrderResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every mutating order action returns this whole shape — the register's totals are
 * incapable of drifting because there is no client-side total to be stale.
 */
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'table_ref' => $this->table_ref,
            'business_date' => $this->business_date,
            'prices_include_tax' => $this->prices_include_tax,
            'subtotal_cents' => $this->subtotal_cents,
            'discount_cents' => $this->discount_cents,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'paid_cents' => $this->paid_cents,
            'version' => $this->version,
            'lines' => OrderLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
```

```php
<?php
// app/Http/Resources/OrderLineResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name_snapshot,     // snapshots; never the live catalog
            'sku' => $this->sku_snapshot,
            'unit_price_cents' => $this->unit_price_cents,
            'qty' => $this->qty,                // string, always
            'tax_cents' => $this->tax_cents,
            'line_total_cents' => $this->line_total_cents,
            'voided_at' => $this->voided_at?->toIso8601String(),
        ];
    }
}
```

Routes — inside the `staff` group:

```php
Route::post('/orders', OpenOrderController::class)->name('orders.open');
Route::post('/orders/{order}/lines', AddLineController::class)
    ->middleware('idempotent')
    ->name('orders.lines.add');
```

- [ ] **Step 6: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Orders/ && ./vendor/bin/pest tests/Arch`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app backend/routes backend/tests
git commit -m "M3: open order + add line — snapshots, stock, totals, version, atomically"
```

---

### Task 9: Payments — driver seam and TakePayment

**Files:**
- Create: `backend/app/Domain/Payments/PaymentDriver.php`, `Capabilities.php`, `PaymentIntent.php`, `PaymentResult.php`, `CashDriver.php`, `DriverRegistry.php`
- Create: `backend/app/Actions/Payments/TakePayment.php`, `TakePaymentInput.php`
- Create: `backend/app/Exceptions/Domain/PaymentExceedsBalance.php`
- Create: `backend/app/Http/Controllers/Payments/TakePaymentController.php`, `backend/app/Http/Requests/Payments/TakePaymentRequest.php`, `backend/app/Http/Resources/TakePaymentResource.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (bind `DriverRegistry`), `backend/routes/api.php`
- Test: `backend/tests/Feature/Payments/TakePaymentTest.php`

**Interfaces:**
- Consumes: `Tender::cash(Money $applied, Money $tendered)` / `Tender::exact(Money $applied)` (M1 — `Tender::cash` throws the existing `InsufficientTender`, 422), `InsufficientTender` exception (exists), Order/Shift models, `idempotent` middleware.
- Produces:
  - `PaymentDriver` interface: `code(): string`, `capabilities(): Capabilities`, `authorize(PaymentIntent): PaymentResult`, `capture(Payment): PaymentResult`, `refund(Payment, Money): PaymentResult`, `void(Payment): PaymentResult` — the seam from `docs/01-architecture.md`, defined now so Stripe Terminal later adds a class and touches no action.
  - `CashDriver` — `authorize` settles immediately (`status: 'captured'`, `Tender` carries change); `capture` is a contained no-op.
  - `DriverRegistry::driver(string $code): PaymentDriver` — singleton with `cash` only (external_card is M4).
  - `TakePayment::execute(TakePaymentInput{orderId, registerId, driver, amountCents, tenderedCents, reference, expectedVersion, actorId}): Payment` (with `order` relation set) — lock, status+version check, `NoOpenShift`, `PaymentExceedsBalance` (422), writes captured payment, bumps `paid_cents`, **auto-closes at paid-in-full** (no close endpoint exists, by design), bumps version, audits.
  - Route: `POST /api/v1/orders/{order}/payments` (staff + `idempotent`; key REQUIRED via request rule).
  - Response `data`: `{ payment: {id, driver, status, amount_cents, tendered_cents, change_cents}, order: {...OrderResource} }`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Payments/TakePaymentTest.php
declare(strict_types=1);

use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\InsufficientTender;
use App\Exceptions\Domain\NoOpenShift;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\PaymentExceedsBalance;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Shift;
use Illuminate\Support\Str;

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

function takeCash(object $t, int $amount, ?int $tendered = null, ?int $version = null): \App\Models\Payment
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $t->order->id,
        registerId: $t->register->id,
        driver: 'cash',
        amountCents: $amount,
        tenderedCents: $tendered,
        reference: null,
        expectedVersion: $version ?? Order::findOrFail($t->order->id)->version,
        actorId: $t->cashier->id,
    ));
}

it('captures cash, computes change in integers, and auto-closes at paid in full', function (): void {
    $payment = takeCash($this, 5000, tendered: 6000);

    expect($payment->status)->toBe('captured')
        ->and($payment->amount_cents)->toBe(5000)
        ->and($payment->tendered_cents)->toBe(6000)
        ->and($payment->change_cents)->toBe(1000)
        ->and($payment->shift_id)->toBe($this->shift->id);

    $order = $payment->order;
    expect($order->paid_cents)->toBe(5000)
        ->and($order->status)->toBe(OrderStatus::Closed)
        ->and($order->closed_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'payment.take', 'entity_id' => $payment->id]);
});

it('a partial payment leaves the order open — splitting is just several payments', function (): void {
    takeCash($this, 2000);
    expect(Order::findOrFail($this->order->id)->status)->toBe(OrderStatus::Open);

    $second = takeCash($this, 3000);
    expect($second->order->status)->toBe(OrderStatus::Closed)
        ->and($second->order->paid_cents)->toBe(5000);
});

it('overpaying is an error, not change', function (): void {
    expect(fn () => takeCash($this, 6000))->toThrow(PaymentExceedsBalance::class);
});

it('tendering less than the amount applied is insufficient_tender', function (): void {
    expect(fn () => takeCash($this, 5000, tendered: 4000))->toThrow(InsufficientTender::class);
});

it('requires an open shift on the register taking the payment', function (): void {
    $this->shift->forceFill(['closed_at' => now(), 'counted_cash_cents' => 0])->save();

    expect(fn () => takeCash($this, 5000))->toThrow(NoOpenShift::class);
});

it('rejects payment on a closed order', function (): void {
    takeCash($this, 5000);

    expect(fn () => takeCash($this, 1000))->toThrow(OrderClosed::class);
});

it('a replayed idempotency key charges once', function (): void {
    $headers = staffHeaders($this->register, $this->cashier)
        + ['If-Match' => '0', 'Idempotency-Key' => (string) Str::uuid()];
    $body = ['driver' => 'cash', 'amount_cents' => 5000, 'tendered_cents' => 6000];

    $first = $this->postJson("/api/v1/orders/{$this->order->id}/payments", $body, $headers);
    $second = $this->postJson("/api/v1/orders/{$this->order->id}/payments", $body, $headers);

    $first->assertCreated()
        ->assertJsonPath('data.payment.change_cents', 1000)
        ->assertJsonPath('data.order.status', 'closed');
    expect($second->json())->toBe($first->json())
        ->and(\App\Models\Payment::where('order_id', $this->order->id)->count())->toBe(1);
});

it('requires the Idempotency-Key header', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/payments",
        ['driver' => 'cash', 'amount_cents' => 5000], $headers)->assertStatus(422);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Payments/`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the driver seam**

```php
<?php
// app/Domain/Payments/Capabilities.php
declare(strict_types=1);

namespace App\Domain\Payments;

final readonly class Capabilities
{
    public function __construct(
        public bool $refundable,
        public bool $async,
    ) {}
}
```

```php
<?php
// app/Domain/Payments/PaymentIntent.php
declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Money;

final readonly class PaymentIntent
{
    public function __construct(
        public Money $amount,
        public ?Money $tendered,     // cash only
        public ?string $reference,   // external terminal reference
    ) {}
}
```

```php
<?php
// app/Domain/Payments/PaymentResult.php
declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Tender;

final readonly class PaymentResult
{
    public function __construct(
        public string $status,       // 'captured' | 'pending' | 'failed'
        public ?Tender $tender,      // cash: applied/tendered/change; null otherwise
        public ?string $reference,
    ) {}
}
```

```php
<?php
// app/Domain/Payments/PaymentDriver.php
declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Money;
use App\Models\Payment;

/**
 * The payment seam from docs/01-architecture.md. authorize/capture even though cash
 * doesn't need two steps — a driver that can't say "pending, waiting on the customer
 * to tap" forces every caller to be rewritten the day a real reader arrives.
 */
interface PaymentDriver
{
    public function code(): string;

    public function capabilities(): Capabilities;

    /** Begin a tender. May complete immediately (cash) or return pending (terminal). */
    public function authorize(PaymentIntent $intent): PaymentResult;

    /** Settle a prior authorization. Cash is a no-op; card processors are not. */
    public function capture(Payment $payment): PaymentResult;

    public function refund(Payment $payment, Money $amount): PaymentResult;

    public function void(Payment $payment): PaymentResult;
}
```

```php
<?php
// app/Domain/Payments/CashDriver.php
declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Money;
use App\Domain\Money\Tender;
use App\Models\Payment;

final class CashDriver implements PaymentDriver
{
    public function code(): string
    {
        return 'cash';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(refundable: true, async: false);
    }

    public function authorize(PaymentIntent $intent): PaymentResult
    {
        // Tender::cash throws InsufficientTender (422) when tendered < applied —
        // handing over less than you're applying is impossible, not a partial payment.
        $tender = $intent->tendered === null
            ? Tender::exact($intent->amount)
            : Tender::cash($intent->amount, $intent->tendered);

        return new PaymentResult(status: 'captured', tender: $tender, reference: null);
    }

    // Cash settles the moment it hits the drawer. A contained no-op here beats
    // reshaping the order flow the day an async driver arrives.
    public function capture(Payment $payment): PaymentResult
    {
        return new PaymentResult(status: 'captured', tender: null, reference: null);
    }

    public function refund(Payment $payment, Money $amount): PaymentResult
    {
        return new PaymentResult(status: 'captured', tender: null, reference: null);
    }

    public function void(Payment $payment): PaymentResult
    {
        return new PaymentResult(status: 'voided', tender: null, reference: null);
    }
}
```

```php
<?php
// app/Domain/Payments/DriverRegistry.php
declare(strict_types=1);

namespace App\Domain\Payments;

use InvalidArgumentException;

/** Adding a processor = a driver class + one entry here. No action changes. */
final class DriverRegistry
{
    /** @var array<string, PaymentDriver> */
    private array $drivers = [];

    public function __construct(PaymentDriver ...$drivers)
    {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->code()] = $driver;
        }
    }

    public function driver(string $code): PaymentDriver
    {
        return $this->drivers[$code]
            ?? throw new InvalidArgumentException("No payment driver '{$code}' is registered.");
    }
}
```

In `AppServiceProvider::register()`:

```php
$this->app->singleton(\App\Domain\Payments\DriverRegistry::class,
    fn (): \App\Domain\Payments\DriverRegistry => new \App\Domain\Payments\DriverRegistry(
        new \App\Domain\Payments\CashDriver(),
    ));
```

- [ ] **Step 4: Implement exception + action**

`PaymentExceedsBalance`: `errorCode()` `payment_exceeds_balance`, `httpStatus()` 422, ctor `(string $orderId, int $amountCents, int $balanceCents)`, details `['order_id' => ..., 'amount_cents' => ..., 'balance_cents' => ...]`, message "For cash, the overage is change, not payment."

```php
<?php
// app/Actions/Payments/TakePaymentInput.php
declare(strict_types=1);

namespace App\Actions\Payments;

final readonly class TakePaymentInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        public string $driver,
        public int $amountCents,
        public ?int $tenderedCents,
        public ?string $reference,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
```

```php
<?php
// app/Actions/Payments/TakePayment.php
declare(strict_types=1);

namespace App\Actions\Payments;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Money;
use App\Domain\Payments\DriverRegistry;
use App\Domain\Payments\PaymentIntent;
use App\Exceptions\Domain\NoOpenShift;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Exceptions\Domain\PaymentExceedsBalance;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Applies a tender. The order closes automatically when captured payments reach
 * total_cents — a manual close endpoint would be a second, disagreeing definition of
 * "paid in full". The payment's shift is the register's CURRENT shift, which is what
 * makes drawer variance computable when a tab spans a shift boundary.
 */
final class TakePayment
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(TakePaymentInput $in): Payment
    {
        return DB::transaction(function () use ($in): Payment {
            $order = Order::whereKey($in->orderId)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Open) {
                throw new OrderClosed($order->id, $order->status->value);
            }
            if ($order->version !== $in->expectedVersion) {
                throw new OrderVersionConflict($order->id, $in->expectedVersion, $order->version);
            }

            $shift = Shift::where('register_id', $in->registerId)->whereNull('closed_at')->first()
                ?? throw new NoOpenShift($in->registerId);

            $balance = $order->total_cents - $order->paid_cents;
            if ($in->amountCents > $balance) {
                // For cash the overage is change, not payment — the client should have
                // sent amount = balance and tendered = what was handed over.
                throw new PaymentExceedsBalance($order->id, $in->amountCents, $balance);
            }

            $result = $this->drivers->driver($in->driver)->authorize(new PaymentIntent(
                amount: Money::fromCents($in->amountCents),
                tendered: $in->tenderedCents === null ? null : Money::fromCents($in->tenderedCents),
                reference: $in->reference,
            ));

            $payment = Payment::create([
                'order_id' => $order->id,
                'shift_id' => $shift->id,
                'driver' => $in->driver,
                'status' => $result->status,
                'amount_cents' => $in->amountCents,
                'tendered_cents' => $result->tender?->tendered->cents,
                'change_cents' => $result->tender?->change->cents,
                'reference' => $result->reference ?? $in->reference,
                'user_id' => $in->actorId,
                'created_at' => now(),
                'captured_at' => $result->status === 'captured' ? now() : null,
            ]);

            if ($result->status === 'captured') {
                $order->paid_cents += $in->amountCents;
            }
            if ($order->paid_cents === $order->total_cents && $order->total_cents > 0) {
                $order->status = OrderStatus::Closed;
                $order->closed_at = now();
                $order->closed_by = $in->actorId;
            }
            $order->version += 1;
            $order->save();

            $this->audit->record('payment.take', $payment, $in->actorId, [
                'order_id' => $order->id,
                'driver' => $in->driver,
                'amount_cents' => $in->amountCents,
            ], registerId: $in->registerId);

            return $payment->setRelation('order', $order->fresh(['lines']));
        });
    }
}
```

- [ ] **Step 5: Implement HTTP layer**

```php
<?php
// app/Http/Requests/Payments/TakePaymentRequest.php
declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Actions\Payments\TakePaymentInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class TakePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::PAYMENT_TAKE);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'if_match' => $this->header('If-Match'),
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'driver' => ['required', 'in:cash'],   // external_card lands in M4
            'amount_cents' => ['required', 'integer', 'min:1'],
            'tendered_cents' => ['nullable', 'integer', 'min:1'],   // absent = exact tender
            'reference' => ['nullable', 'string', 'max:100'],
            'if_match' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function toInput(): TakePaymentInput
    {
        return new TakePaymentInput(
            orderId: (string) $this->route('order'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            driver: $this->string('driver')->toString(),
            amountCents: $this->integer('amount_cents'),
            tenderedCents: $this->filled('tendered_cents') ? $this->integer('tendered_cents') : null,
            reference: $this->string('reference')->toString() ?: null,
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
```

```php
<?php
// app/Http/Controllers/Payments/TakePaymentController.php
declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\TakePayment;
use App\Http\Requests\Payments\TakePaymentRequest;
use App\Http\Resources\TakePaymentResource;
use Illuminate\Http\JsonResponse;

final class TakePaymentController
{
    public function __invoke(TakePaymentRequest $request, TakePayment $action): JsonResponse
    {
        return (new TakePaymentResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
```

```php
<?php
// app/Http/Resources/TakePaymentResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** { payment, order } — the change the drawer owes and the state the register renders. */
final class TakePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'payment' => [
                'id' => $this->id,
                'driver' => $this->driver,
                'status' => $this->status,
                'amount_cents' => $this->amount_cents,
                'tendered_cents' => $this->tendered_cents,
                'change_cents' => $this->change_cents,
            ],
            'order' => new OrderResource($this->whenLoaded('order')),
        ];
    }
}
```

Route — inside the `staff` group:

```php
Route::post('/orders/{order}/payments', TakePaymentController::class)
    ->middleware('idempotent')
    ->name('orders.payments.take');
```

- [ ] **Step 6: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Payments/ && ./vendor/bin/pest tests/Arch`
Expected: PASS — including "a replayed idempotency key charges once", the invariant test named in `docs/01-architecture.md`.

- [ ] **Step 7: Commit**

```bash
git add backend/app backend/routes backend/tests
git commit -m "M3: cash payments — driver seam, change in integers, auto-close, replay-safe"
```

---

### Task 10: Receipt

**Files:**
- Create: `backend/app/Actions/Orders/GetReceipt.php`, `backend/app/Http/Controllers/Orders/ReceiptController.php`, `backend/app/Http/Resources/ReceiptResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Orders/ReceiptTest.php`

**Interfaces:**
- Consumes: `Order` with `lines` (non-voided, by position), captured `payments`, `location`, `openedBy`; `config('pos.business')`, `config('pos.currency')`.
- Produces: `GET /api/v1/orders/{order}/receipt` (staff tier) → structured JSON rendered client-side, built ENTIRELY from snapshot columns — reprinting a 2024 receipt next year produces identical bytes. Shape:

```json
{
  "business": { "name": "...", "address": "...", "tax_id": null },
  "location": { "name": "...", "header": "...", "footer": "..." },
  "order": { "number": "DT-20260716-0001", "business_date": "2026-07-16",
             "opened_at": "...", "closed_at": "...", "table_ref": null,
             "cashier": "Alice Cashier", "prices_include_tax": false },
  "lines": [ { "name": "T-Shirt — Blue / L", "sku": "TSHIRT-BLUE-L", "qty": "2.000",
               "unit_price_cents": 1999, "line_total_cents": 3998, "tax_cents": 355 } ],
  "totals": { "subtotal_cents": 3998, "discount_cents": 0, "tax_cents": 355,
              "total_cents": 4353, "paid_cents": 4353 },
  "payments": [ { "driver": "cash", "amount_cents": 4353, "tendered_cents": 5000, "change_cents": 647 } ],
  "currency": "USD"
}
```

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Orders/ReceiptTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\AddLineInput;
use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\ProductVariant;

beforeEach(function (): void {
    $this->location = provisionedLocation([
        'receipt_header' => 'Downtown Store', 'receipt_footer' => 'Thanks!', 'prices_include_tax' => false,
    ]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $this->variant = ProductVariant::factory()->untracked()->create(['price_cents' => 1999]);

    app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, variantId: $this->variant->id, qty: '1',
        expectedVersion: 0, actorId: $this->cashier->id,
    ));
});

it('builds the receipt from snapshots and survives a catalog rename + reprice', function (): void {
    $headers = staffHeaders($this->register, $this->cashier);

    $before = $this->getJson("/api/v1/orders/{$this->order->id}/receipt", $headers)->assertOk()->json();

    // History must not be rewritable by a Tuesday catalog edit.
    $this->variant->update(['price_cents' => 9999]);
    $this->variant->product->update(['name' => 'Renamed Product']);

    $after = $this->getJson("/api/v1/orders/{$this->order->id}/receipt", $headers)->json();

    expect($after)->toBe($before)
        ->and($before['data']['lines'][0]['unit_price_cents'])->toBe(1999)
        ->and($before['data']['location']['header'])->toBe('Downtown Store')
        ->and($before['data']['totals']['total_cents'])->toBe($before['data']['totals']['subtotal_cents'] + $before['data']['totals']['tax_cents']);
});

it('excludes voided lines', function (): void {
    $this->order->lines()->first()->forceFill(['voided_at' => now()])->save();

    $this->getJson("/api/v1/orders/{$this->order->id}/receipt", staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonCount(0, 'data.lines');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Orders/ReceiptTest.php`
Expected: FAIL — 404 route not found.

- [ ] **Step 3: Implement**

```php
<?php
// app/Actions/Orders/GetReceipt.php
declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;

/**
 * Loads exactly what a receipt renders. Every displayed value comes from snapshot
 * columns on order_lines/orders — the live catalog is never consulted, which is the
 * entire reason those columns exist. See docs/02-data-model.md.
 */
final class GetReceipt
{
    public function execute(string $orderId): Order
    {
        return Order::with([
            'lines' => fn ($q) => $q->whereNull('voided_at')->orderBy('position'),
            'payments' => fn ($q) => $q->where('status', 'captured')->orderBy('created_at'),
            'location',
            'openedBy',
        ])->findOrFail($orderId);
    }
}
```

```php
<?php
// app/Http/Controllers/Orders/ReceiptController.php
declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\GetReceipt;
use App\Http\Resources\ReceiptResource;
use Illuminate\Http\Request;

final class ReceiptController
{
    public function __invoke(Request $request, GetReceipt $action): ReceiptResource
    {
        return new ReceiptResource($action->execute((string) $request->route('order')));
    }
}
```

```php
<?php
// app/Http/Resources/ReceiptResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The server decides WHAT a receipt says; the client (and one day the desktop shell)
 * decides HOW to put it on paper. Snapshot columns only — a reprint next year must
 * produce identical bytes.
 */
final class ReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'business' => [
                'name' => config('pos.business.name'),
                'address' => config('pos.business.address'),
                'tax_id' => config('pos.business.tax_id'),
            ],
            'location' => [
                'name' => $this->location->name,
                'header' => $this->location->receipt_header,
                'footer' => $this->location->receipt_footer,
            ],
            'order' => [
                'number' => $this->number,
                'business_date' => $this->business_date,
                'opened_at' => $this->opened_at?->toIso8601String(),
                'closed_at' => $this->closed_at?->toIso8601String(),
                'table_ref' => $this->table_ref,
                'cashier' => $this->openedBy->name,
                'prices_include_tax' => $this->prices_include_tax,
            ],
            'lines' => $this->lines->map(fn ($line): array => [
                'name' => $line->name_snapshot,
                'sku' => $line->sku_snapshot,
                'qty' => $line->qty,
                'unit_price_cents' => $line->unit_price_cents,
                'line_total_cents' => $line->line_total_cents,
                'tax_cents' => $line->tax_cents,
            ])->values()->all(),
            'totals' => [
                'subtotal_cents' => $this->subtotal_cents,
                'discount_cents' => $this->discount_cents,
                'tax_cents' => $this->tax_cents,
                'total_cents' => $this->total_cents,
                'paid_cents' => $this->paid_cents,
            ],
            'payments' => $this->payments->map(fn ($p): array => [
                'driver' => $p->driver,
                'amount_cents' => $p->amount_cents,
                'tendered_cents' => $p->tendered_cents,
                'change_cents' => $p->change_cents,
            ])->values()->all(),
            'currency' => config('pos.currency'),
        ];
    }
}
```

Route — inside the `staff` group:

```php
Route::get('/orders/{order}/receipt', ReceiptController::class)->name('orders.receipt');
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Orders/ReceiptTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app backend/routes backend/tests
git commit -m "M3: receipt JSON from snapshots — reprints are byte-identical"
```

---

### Task 11: Catalog — full payload and barcode lookup

**Files:**
- Create: `backend/app/Actions/Catalog/GetCatalog.php`, `CatalogSnapshot.php`, `LookupBarcode.php`, `ResolvedVariant.php`
- Create: `backend/app/Http/Controllers/Catalog/GetCatalogController.php`, `LookupBarcodeController.php`
- Create: `backend/app/Http/Resources/CatalogResource.php`, `ResolvedVariantResource.php`
- Modify: `backend/routes/api.php`, `backend/app/Providers/AppServiceProvider.php` (add the `catalog` rate limiter next to the existing `pin` limiter — check where M2 defined it and match)
- Test: `backend/tests/Feature/Catalog/CatalogTest.php`, `BarcodeLookupTest.php`

**Interfaces:**
- Consumes: catalog models (M2), `PriceResolver` (Task 3), `EnsureDeviceToken::REGISTER`.
- Produces:
  - `GetCatalog::execute(string $locationId): CatalogSnapshot{categories, products, variants, modifierGroups, modifiers, taxRates}` — one denormalized payload; variant prices already resolved for the location. Products carry `modifier_group_ids`.
  - `LookupBarcode::execute(string $barcode, string $locationId): ResolvedVariant{variant, price}` — 404 when unknown. The scanner's hot path.
  - Routes (device tier — no staff): `GET /api/v1/catalog` (`throttle:catalog`), `GET /api/v1/catalog/lookup?barcode=`.
  - Location comes from the enrolled register, never a query param (see scope cuts). `updated_since` deferred.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Catalog/CatalogTest.php
declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->device = ['Authorization' => 'Bearer '.$this->register->createToken("device:{$this->register->id}", ['device'])->plainTextToken];
});

it('returns one denormalized payload with location-resolved prices', function (): void {
    $variant = ProductVariant::factory()->create(['price_cents' => 1999]);
    DB::table('variant_location_prices')->insert([
        'variant_id' => $variant->id, 'location_id' => $this->location->id, 'price_cents' => 2499,
    ]);

    $response = $this->getJson('/api/v1/catalog', $this->device)->assertOk()->json('data');

    expect($response)->toHaveKeys(['categories', 'products', 'variants', 'modifier_groups', 'modifiers', 'tax_rates']);

    $wire = collect($response['variants'])->firstWhere('id', $variant->id);
    expect($wire['price_cents'])->toBe(2499);   // resolved server-side; the register never resolves prices
});

it('hides inactive and soft-deleted variants', function (): void {
    $dead = ProductVariant::factory()->create(['is_active' => false]);
    $gone = ProductVariant::factory()->create();
    $gone->delete();

    $ids = collect($this->getJson('/api/v1/catalog', $this->device)->json('data.variants'))->pluck('id');

    expect($ids)->not->toContain($dead->id)->not->toContain($gone->id);
});

it('requires a device token', function (): void {
    $this->getJson('/api/v1/catalog')->assertStatus(401);
});
```

```php
<?php
// tests/Feature/Catalog/BarcodeLookupTest.php
declare(strict_types=1);

use App\Models\ProductVariant;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->device = ['Authorization' => 'Bearer '.$this->register->createToken("device:{$this->register->id}", ['device'])->plainTextToken];
});

it('resolves a barcode to a variant with the location price', function (): void {
    $variant = ProductVariant::factory()->create(['barcode' => '012345678905', 'price_cents' => 1999]);

    $this->getJson('/api/v1/catalog/lookup?barcode=012345678905', $this->device)
        ->assertOk()
        ->assertJsonPath('data.variant.id', $variant->id)
        ->assertJsonPath('data.variant.price_cents', 1999);
});

it('404s an unknown barcode with the standard envelope', function (): void {
    $this->getJson('/api/v1/catalog/lookup?barcode=000000000000', $this->device)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});
```

(Verify the 404 `error.code` value against `app/Exceptions/ApiErrorEnvelope.php` — M0 mapped framework exceptions; use whatever code it emits for `ModelNotFoundException`.)

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Catalog/`
Expected: FAIL — 404 route not found.

- [ ] **Step 3: Implement**

```php
<?php
// app/Actions/Catalog/CatalogSnapshot.php
declare(strict_types=1);

namespace App\Actions\Catalog;

final readonly class CatalogSnapshot
{
    public function __construct(
        public array $categories,
        public array $products,
        public array $variants,
        public array $modifierGroups,
        public array $modifiers,
        public array $taxRates,
    ) {}
}
```

```php
<?php
// app/Actions/Catalog/GetCatalog.php
declare(strict_types=1);

namespace App\Actions\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * One denormalized payload, not five REST resources — a register needs the whole menu
 * to render, and five round-trips on a cold start is five chances to half-load it.
 * Prices are resolved for the location HERE; the register never implements pricing.
 * (updated_since delta sync deferred: modifiers has no updated_at to diff on.)
 */
final class GetCatalog
{
    public function execute(string $locationId): CatalogSnapshot
    {
        $groupIdsByProduct = DB::table('product_modifier_groups')
            ->orderBy('position')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('group_id')->all());

        $products = DB::table('products')->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'category_id', 'kind'])
            ->map(fn ($p): array => (array) $p + ['modifier_group_ids' => $groupIdsByProduct[$p->id] ?? []])
            ->all();

        $variants = DB::table('product_variants as v')
            ->leftJoin('variant_location_prices as vlp', function ($join) use ($locationId): void {
                $join->on('vlp.variant_id', '=', 'v.id')->where('vlp.location_id', $locationId);
            })
            ->whereNull('v.deleted_at')
            ->where('v.is_active', true)
            ->orderBy('v.position')
            ->get([
                'v.id', 'v.product_id', 'v.name', 'v.sku', 'v.barcode',
                DB::raw('coalesce(vlp.price_cents, v.price_cents) as price_cents'),
                'v.tax_rate_id', 'v.track_inventory', 'v.position',
            ])
            ->map(fn ($v): array => (array) $v)
            ->all();

        return new CatalogSnapshot(
            categories: DB::table('categories')->orderBy('sort_order')
                ->get(['id', 'name', 'parent_id', 'sort_order'])->map(fn ($r): array => (array) $r)->all(),
            products: $products,
            variants: $variants,
            modifierGroups: DB::table('modifier_groups')
                ->get(['id', 'name', 'min_select', 'max_select'])->map(fn ($r): array => (array) $r)->all(),
            modifiers: DB::table('modifiers')->where('is_active', true)->orderBy('position')
                ->get(['id', 'group_id', 'name', 'price_delta_cents', 'position'])->map(fn ($r): array => (array) $r)->all(),
            taxRates: DB::table('tax_rates')->where('is_active', true)
                ->get(['id', 'name', 'rate_micros'])->map(fn ($r): array => (array) $r)->all(),
        );
    }
}
```

```php
<?php
// app/Actions/Catalog/ResolvedVariant.php
declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Money\Money;
use App\Models\ProductVariant;

final readonly class ResolvedVariant
{
    public function __construct(
        public ProductVariant $variant,
        public Money $price,
    ) {}
}
```

```php
<?php
// app/Actions/Catalog/LookupBarcode.php
declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Pricing\PriceResolver;
use App\Models\ProductVariant;

/**
 * The scanner path: the hottest read in retail, kept to indexed lookups. 404 renders
 * through the standard envelope.
 */
final class LookupBarcode
{
    public function __construct(private readonly PriceResolver $prices) {}

    public function execute(string $barcode, string $locationId): ResolvedVariant
    {
        $variant = ProductVariant::query()->active()
            ->where('barcode', $barcode)
            ->with('product')
            ->firstOrFail();

        return new ResolvedVariant($variant, $this->prices->for($variant, $locationId));
    }
}
```

```php
<?php
// app/Http/Controllers/Catalog/GetCatalogController.php
declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\GetCatalog;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Resources\CatalogResource;
use Illuminate\Http\Request;

final class GetCatalogController
{
    public function __invoke(Request $request, GetCatalog $action): CatalogResource
    {
        return new CatalogResource($action->execute($request->attributes->get(EnsureDeviceToken::REGISTER)->location_id));
    }
}
```

```php
<?php
// app/Http/Controllers/Catalog/LookupBarcodeController.php
declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\LookupBarcode;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Resources\ResolvedVariantResource;
use Illuminate\Http\Request;

final class LookupBarcodeController
{
    public function __invoke(Request $request, LookupBarcode $action): ResolvedVariantResource
    {
        $request->validate(['barcode' => ['required', 'string']]);

        return new ResolvedVariantResource($action->execute(
            $request->query('barcode'),
            $request->attributes->get(EnsureDeviceToken::REGISTER)->location_id,
        ));
    }
}
```

(Four lines including validation — acceptable; a FormRequest for one query param on an unauthenticated-tier GET would be ceremony. If the arch test objects, promote to a FormRequest.)

```php
<?php
// app/Http/Resources/CatalogResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'categories' => $this->categories,
            'products' => $this->products,
            'variants' => $this->variants,
            'modifier_groups' => $this->modifierGroups,
            'modifiers' => $this->modifiers,
            'tax_rates' => $this->taxRates,
        ];
    }
}
```

```php
<?php
// app/Http/Resources/ResolvedVariantResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ResolvedVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'variant' => [
                'id' => $this->variant->id,
                'product_id' => $this->variant->product_id,
                'name' => $this->variant->displayName(),
                'sku' => $this->variant->sku,
                'barcode' => $this->variant->barcode,
                'price_cents' => $this->price->cents,
                'track_inventory' => $this->variant->track_inventory,
            ],
        ];
    }
}
```

Routes — inside the `device` group, OUTSIDE the `staff` group (a terminal showing the menu before anyone clocks in is normal):

```php
Route::get('/catalog', GetCatalogController::class)
    ->middleware('throttle:catalog')
    ->name('catalog.get');
Route::get('/catalog/lookup', LookupBarcodeController::class)->name('catalog.lookup');
```

Rate limiter — wherever M2 registered `pin` (likely `AppServiceProvider::boot()`), add:

```php
RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(config('pos.rate_limits.catalog_per_minute'))
    ->by($request->bearerToken() ?? $request->ip()));
```

- [ ] **Step 4: Run the tests and make sure they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Catalog/ && ./vendor/bin/pest tests/Arch`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app backend/routes backend/tests
git commit -m "M3: catalog payload with resolved prices + barcode lookup"
```

---

### Task 12: Seeder — stock on shelves and printed device tokens

**Files:**
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Test: manual (seeder runs under `migrate:fresh --seed`; the invariant it must respect is already tested in Task 4)

**Interfaces:**
- Consumes: `StockLedger::receive` (Task 4).
- Produces: every tracked seed variant has stock at both locations (via the ledger, never a bare `stock_levels` insert — the invariant `qty = sum(movements)` holds from row one); the seeder prints a device token per register so the SPA can be used without building an enrollment UI (enrollment stays admin-API-only in v1).

- [ ] **Step 1: Extend the seeder**

In `DatabaseSeeder::run()`, capture registers and print device tokens. Replace the register-creation loop with:

```php
$tokens = [];
foreach ([$downtown, $london] as $location) {
    $provisioner->provisionForLocation($location);

    foreach (['Till 1', 'Till 2'] as $name) {
        $register = Register::factory()->create(['location_id' => $location->id, 'name' => $name]);
        $tokens[] = [
            $location->code.' / '.$name,
            $register->createToken("device:{$register->id}", ['device'])->plainTextToken,
        ];
    }
}
```

At the end of `run()`, after the PIN table:

```php
$this->command?->newLine();
$this->command?->info('Device tokens (paste one into the register SPA):');
$this->command?->table(['Register', 'Device token'], $tokens);
```

Make `seedCatalog()` return the tracked variants it creates (the three t-shirts and the cheese), then stock them:

```php
use App\Domain\Money\Quantity;
use App\Domain\Stock\StockLedger;
use Illuminate\Support\Facades\DB;

// ...in run(), after seedCatalog():
$tracked = $this->seedCatalog();   // now returns ProductVariant[]

$ledger = app(StockLedger::class);
DB::transaction(function () use ($ledger, $tracked, $downtown, $london): void {
    foreach ($tracked as $variant) {
        foreach ([$downtown, $london] as $location) {
            // Through the ledger, so stock_levels.qty = sum(movements) from day one.
            $ledger->receive($variant->id, $location->id, Quantity::fromString('20'), note: 'seed');
        }
    }
});
```

(Adjust `seedCatalog()`'s signature to `: array`, collect the t-shirt variants and the cheese variant into a list, and return it. The latte stays untracked and unstocked.)

- [ ] **Step 2: Verify it runs**

Run: `cd backend && php artisan migrate:fresh --seed`
Expected: exits 0; prints PINs AND four device tokens; then

```bash
docker exec pos-postgres psql -U pos -d pos -c "select count(*) from stock_levels; select count(*) from stock_movements;"
```

Expected: 8 level rows (4 variants × 2 locations), 8 movement rows.

- [ ] **Step 3: Commit**

```bash
git add backend/database
git commit -m "M3: seed stock through the ledger and print dev device tokens"
```

---

### Task 13: Frontend API client — tokens, headers, M3 endpoints

**Files:**
- Modify: `frontend/web/src/lib/api.ts`
- Test: `npx tsc -b --force` (type-level); behaviour is exercised by the existing suite + Task 15's end-to-end run. The client contains no money math to unit-test — that is the point.

**Interfaces:**
- Consumes: the existing `request<T>()`/`ApiError` core (keep it).
- Produces: token storage (`pos.device_token`, `pos.staff_token` in `localStorage`), auth headers on every call, and typed wrappers: `api.staffLogin/staffLogout/openShift/currentShift/closeShift/lookupBarcode/openOrder/addLine/takePayment/receipt`. Mutations on orders send `If-Match`; payment + shift close generate `Idempotency-Key` via `crypto.randomUUID()`.

- [ ] **Step 1: Extend `api.ts`**

Add below the `ApiError` class (keep everything existing):

```ts
// ---------------------------------------------------------------------------
// Tokens. The device token is durable (the terminal's identity); the staff token
// is a short session and dies at shift close — the server revokes it, we just
// mirror that by clearing on 401.
// ---------------------------------------------------------------------------

const DEVICE_TOKEN_KEY = 'pos.device_token'
const STAFF_TOKEN_KEY = 'pos.staff_token'

export const tokens = {
  device: () => localStorage.getItem(DEVICE_TOKEN_KEY),
  setDevice: (t: string) => localStorage.setItem(DEVICE_TOKEN_KEY, t),
  staff: () => localStorage.getItem(STAFF_TOKEN_KEY),
  setStaff: (t: string) => localStorage.setItem(STAFF_TOKEN_KEY, t),
  clearStaff: () => localStorage.removeItem(STAFF_TOKEN_KEY),
}
```

Replace the body of `request<T>` so every call carries the tokens (keep the error handling identical):

```ts
async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', ...(init?.headers as Record<string, string>) }
  const device = tokens.device()
  const staff = tokens.staff()
  if (device) headers.Authorization = `Bearer ${device}`
  if (staff) headers['X-Staff-Token'] = staff

  let response: Response
  try {
    response = await fetch(`/api/v1${path}`, { ...init, headers })
  } catch (cause) {
    throw new ApiError('network_unreachable', 'Cannot reach the server.', 0, { cause: String(cause) })
  }
  // ...unchanged from here (envelope parsing, ApiError construction)...
}
```

Add a JSON helper and the wire types:

```ts
function post<T>(path: string, body: unknown, extra?: Record<string, string>): Promise<T> {
  return request<T>(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...extra },
    body: JSON.stringify(body),
  })
}

// ---------------------------------------------------------------------------
// Wire types — cents are plain integers on the wire (docs/03-api.md); brand them
// with money.ts's cents() at the display edge, not here.
// ---------------------------------------------------------------------------

export type StaffSession = {
  staff_token: string
  user: { id: string; name: string }
  expires_at: string
}

export type Shift = {
  id: string
  register_id: string
  opened_by: string
  opened_at: string
  opening_float_cents: number
  closed_at: string | null
  counted_cash_cents: number | null
  expected_cash_cents: number | null
  variance_cents: number | null
}

export type SalesSummary = { orders_closed: number; total_cents: number; cash_cents: number }
export type CurrentShift = { shift: Shift; expected_cash_cents: number; sales_summary: SalesSummary }
export type ShiftCloseResult = { shift: Shift; expected_cash_cents: number; variance_cents: number; requires_approval: boolean }

export type OrderLine = {
  id: string
  name: string
  sku: string
  unit_price_cents: number
  qty: string
  tax_cents: number
  line_total_cents: number
  voided_at: string | null
}

export type Order = {
  id: string
  number: string
  status: 'open' | 'closed' | 'voided'
  table_ref: string | null
  business_date: string
  prices_include_tax: boolean
  subtotal_cents: number
  discount_cents: number
  tax_cents: number
  total_cents: number
  paid_cents: number
  version: number
  lines?: OrderLine[]
}

export type LookedUpVariant = {
  variant: {
    id: string
    product_id: string
    name: string
    sku: string
    barcode: string | null
    price_cents: number
    track_inventory: boolean
  }
}

export type PaymentOutcome = {
  payment: { id: string; driver: string; status: string; amount_cents: number; tendered_cents: number | null; change_cents: number | null }
  order: Order
}

export type Receipt = {
  business: { name: string; address: string | null; tax_id: string | null }
  location: { name: string; header: string | null; footer: string | null }
  order: { number: string; business_date: string; opened_at: string; closed_at: string | null; table_ref: string | null; cashier: string; prices_include_tax: boolean }
  lines: Array<{ name: string; sku: string; qty: string; unit_price_cents: number; line_total_cents: number; tax_cents: number }>
  totals: { subtotal_cents: number; discount_cents: number; tax_cents: number; total_cents: number; paid_cents: number }
  payments: Array<{ driver: string; amount_cents: number; tendered_cents: number | null; change_cents: number | null }>
  currency: string
}
```

Extend the exported `api` object:

```ts
export const api = {
  health: () => request<Health>('/health'),

  staffLogin: async (pin: string): Promise<StaffSession> => {
    const session = await post<StaffSession>('/staff/login', { pin })
    tokens.setStaff(session.staff_token)
    return session
  },
  staffLogout: async (): Promise<void> => {
    await post('/staff/logout', {}).catch(() => undefined)   // best-effort; local state clears regardless
    tokens.clearStaff()
  },

  currentShift: () => request<CurrentShift>('/shifts/current'),
  openShift: (openingFloatCents: number) =>
    post<{ shift: Shift }>('/shifts/open', { opening_float_cents: openingFloatCents }).then((r) => r.shift),
  closeShift: (shiftId: string, countedCashCents: number) =>
    post<ShiftCloseResult>(`/shifts/${shiftId}/close`, { counted_cash_cents: countedCashCents }, { 'Idempotency-Key': crypto.randomUUID() }),

  lookupBarcode: (barcode: string) => request<LookedUpVariant>(`/catalog/lookup?barcode=${encodeURIComponent(barcode)}`),

  openOrder: () => post<Order>('/orders', {}),
  addLine: (order: Order, variantId: string, qty = '1') =>
    post<Order>(`/orders/${order.id}/lines`, { variant_id: variantId, qty }, { 'If-Match': String(order.version) }),
  takePayment: (order: Order, amountCents: number, tenderedCents: number) =>
    post<PaymentOutcome>(`/orders/${order.id}/payments`,
      { driver: 'cash', amount_cents: amountCents, tendered_cents: tenderedCents },
      { 'If-Match': String(order.version), 'Idempotency-Key': crypto.randomUUID() }),

  receipt: (orderId: string) => request<Receipt>(`/orders/${orderId}/receipt`),
}
```

Verify the `StaffSession` field names against `app/Http/Resources/StaffSessionResource.php` (M2) and adjust the type to match reality — the shape above is from `docs/03-api.md`.

- [ ] **Step 2: Typecheck and existing tests**

Run: `cd frontend/web && npx tsc -b --force && npm test`
Expected: clean build, 29 existing tests still pass.

- [ ] **Step 3: Commit**

```bash
git add frontend/web/src/lib/api.ts
git commit -m "M3: API client — tokens, If-Match, idempotency keys, typed endpoints"
```

---

### Task 14: Register UI — scan → cart → tender → change → receipt

**Files:**
- Create: `frontend/web/src/register/SessionScreens.tsx`, `frontend/web/src/register/ShiftScreens.tsx`, `frontend/web/src/register/SaleScreen.tsx`
- Modify: `frontend/web/src/App.tsx`, `frontend/web/src/index.css`
- Test: `npx tsc -b --force && npm test && npm run build`. No new unit tests: every number on screen is a server integer formatted at the edge with the already-tested `formatMoney`; the client holds no money or pricing logic to test. The end-to-end check is Task 15.

**Interfaces:**
- Consumes: `api`/`tokens`/types (Task 13), `formatMoney`, `cents` from `lib/money.ts`.
- Produces: a working register. Flow: no device token → paste-token setup; no staff token → PIN pad; no open shift → open-with-float; then the sale loop. Errors surface via `ApiError.code` (e.g. `insufficient_stock` shows the message; `order_version_conflict` refetches is NOT needed in v1 — single register per browser; show the message). A 401 (`staff_session_expired`) anywhere clears the staff token and returns to the PIN screen.

- [ ] **Step 1: Session screens**

```tsx
// src/register/SessionScreens.tsx
import { useState } from 'react'
import { ApiError, api, tokens, type StaffSession } from '../lib/api'

export function SetupScreen({ onDone }: { onDone: () => void }) {
  const [token, setToken] = useState('')

  return (
    <section className="card">
      <h2>Enroll this terminal</h2>
      <p className="muted">
        Paste a device token — printed by <code>php artisan migrate:fresh --seed</code>, or issued via
        POST /api/v1/registers/enroll.
      </p>
      <form
        onSubmit={(e) => {
          e.preventDefault()
          if (!token.trim()) return
          tokens.setDevice(token.trim())
          onDone()
        }}
      >
        <input value={token} onChange={(e) => setToken(e.target.value)} placeholder="1|xxxxxxxx…" autoFocus />
        <button type="submit">Save</button>
      </form>
    </section>
  )
}

export function PinScreen({ onLoggedIn }: { onLoggedIn: (session: StaffSession) => void }) {
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      onLoggedIn(await api.staffLogin(pin))
    } catch (err) {
      setPin('')
      setError(err instanceof ApiError ? err.message : 'Login failed.')
    }
  }

  return (
    <section className="card">
      <h2>Enter PIN</h2>
      <form onSubmit={submit}>
        <input
          type="password" inputMode="numeric" autoComplete="off" autoFocus
          value={pin} onChange={(e) => setPin(e.target.value)} placeholder="••••"
        />
        <button type="submit">Clock in</button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}
```

- [ ] **Step 2: Shift screens**

```tsx
// src/register/ShiftScreens.tsx
import { useState } from 'react'
import { ApiError, api, type Shift, type ShiftCloseResult } from '../lib/api'
import { cents, formatMoney } from '../lib/money'

const CURRENCY = 'USD'   // display only; the server owns all arithmetic

/** Parse a human dollars-and-cents string to integer cents; '' -> null. */
function toCents(input: string): number | null {
  const m = /^(\d+)(?:\.(\d{1,2}))?$/.exec(input.trim())
  if (!m) return null
  return Number(m[1]) * 100 + Number((m[2] ?? '0').padEnd(2, '0'))
}

export function OpenShiftScreen({ onOpened }: { onOpened: (shift: Shift) => void }) {
  const [float, setFloat] = useState('200.00')
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const amount = toCents(float)
    if (amount === null) return setError('Enter an amount like 200.00')
    try {
      onOpened(await api.openShift(amount))
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not open the shift.')
    }
  }

  return (
    <section className="card">
      <h2>Open shift</h2>
      <form onSubmit={submit}>
        <label>
          Opening float
          <input value={float} onChange={(e) => setFloat(e.target.value)} inputMode="decimal" autoFocus />
        </label>
        <button type="submit">Open drawer</button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}

export function CloseShiftScreen({ shiftId, onClosed, onCancel }: {
  shiftId: string
  onClosed: (result: ShiftCloseResult) => void
  onCancel: () => void
}) {
  const [counted, setCounted] = useState('')
  const [result, setResult] = useState<ShiftCloseResult | null>(null)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const amount = toCents(counted)
    if (amount === null) return setError('Enter the counted cash, like 487.50')
    try {
      setResult(await api.closeShift(shiftId, amount))
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not close the shift.')
    }
  }

  if (result) {
    return (
      <section className={`card ${result.variance_cents === 0 ? 'ok' : 'bad'}`}>
        <h2>Drawer reconciled</h2>
        <dl>
          <dt>Expected</dt><dd>{formatMoney(cents(result.expected_cash_cents), CURRENCY)}</dd>
          <dt>Counted</dt><dd>{formatMoney(cents(result.shift.counted_cash_cents ?? 0), CURRENCY)}</dd>
          <dt>Variance</dt><dd>{formatMoney(cents(result.variance_cents), CURRENCY)}</dd>
        </dl>
        {result.requires_approval && <p className="error">Variance exceeds the threshold — needs supervisor approval.</p>}
        <button onClick={() => onClosed(result)}>Done</button>
      </section>
    )
  }

  return (
    <section className="card">
      <h2>Close shift — count the drawer</h2>
      <form onSubmit={submit}>
        <label>
          Counted cash
          <input value={counted} onChange={(e) => setCounted(e.target.value)} inputMode="decimal" autoFocus />
        </label>
        <button type="submit">Close</button>
        <button type="button" className="secondary" onClick={onCancel}>Back</button>
      </form>
      {error && <p className="error">{error}</p>}
    </section>
  )
}
```

- [ ] **Step 3: Sale screen**

```tsx
// src/register/SaleScreen.tsx
import { useRef, useState } from 'react'
import { ApiError, api, type Order, type PaymentOutcome, type Receipt } from '../lib/api'
import { cents, formatMoney } from '../lib/money'

const CURRENCY = 'USD'
const fm = (n: number) => formatMoney(cents(n), CURRENCY)

function toCents(input: string): number | null {
  const m = /^(\d+)(?:\.(\d{1,2}))?$/.exec(input.trim())
  if (!m) return null
  return Number(m[1]) * 100 + Number((m[2] ?? '0').padEnd(2, '0'))
}

type Phase =
  | { name: 'scanning' }
  | { name: 'tender' }
  | { name: 'done'; outcome: PaymentOutcome; receipt: Receipt | null }

export function SaleScreen({ onCloseShift }: { onCloseShift: () => void }) {
  const [order, setOrder] = useState<Order | null>(null)
  const [phase, setPhase] = useState<Phase>({ name: 'scanning' })
  const [barcode, setBarcode] = useState('')
  const [tendered, setTendered] = useState('')
  const [error, setError] = useState<string | null>(null)
  const scanRef = useRef<HTMLInputElement>(null)

  const scan = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!barcode.trim()) return
    setError(null)
    try {
      const { variant } = await api.lookupBarcode(barcode.trim())
      const current = order ?? (await api.openOrder())   // retail opens implicitly on first scan
      setOrder(await api.addLine(current, variant.id))
      setBarcode('')
    } catch (err) {
      setBarcode('')
      setError(err instanceof ApiError ? err.message : 'Scan failed.')
    }
    scanRef.current?.focus()
  }

  const pay = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!order) return
    const handed = toCents(tendered)
    if (handed === null) return setError('Enter the cash handed over, like 50.00')
    setError(null)
    try {
      const outcome = await api.takePayment(order, order.total_cents - order.paid_cents, handed)
      const receipt = await api.receipt(outcome.order.id).catch(() => null)
      setPhase({ name: 'done', outcome, receipt })
      setOrder(null)
      setTendered('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Payment failed.')
    }
  }

  const newSale = () => {
    setPhase({ name: 'scanning' })
    setError(null)
    setTimeout(() => scanRef.current?.focus(), 0)
  }

  if (phase.name === 'done') {
    const { payment } = phase.outcome
    return (
      <section className="card ok">
        <h2>Change due: {fm(payment.change_cents ?? 0)}</h2>
        <p className="muted">
          {fm(payment.amount_cents)} paid on {fm(payment.tendered_cents ?? payment.amount_cents)} tendered — order {phase.outcome.order.number}
        </p>
        {phase.receipt && (
          <div className="receipt">
            <h3>{phase.receipt.location.header ?? phase.receipt.business.name}</h3>
            <p className="muted">{phase.receipt.order.number} · {phase.receipt.order.business_date} · {phase.receipt.order.cashier}</p>
            <table>
              <tbody>
                {phase.receipt.lines.map((l, i) => (
                  <tr key={i}>
                    <td>{l.name}</td>
                    <td>{l.qty === '1.000' ? '' : l.qty}</td>
                    <td className="num">{fm(l.line_total_cents)}</td>
                  </tr>
                ))}
                <tr><td>Tax</td><td /><td className="num">{fm(phase.receipt.totals.tax_cents)}</td></tr>
                <tr className="total"><td>Total</td><td /><td className="num">{fm(phase.receipt.totals.total_cents)}</td></tr>
              </tbody>
            </table>
            {phase.receipt.location.footer && <p className="muted">{phase.receipt.location.footer}</p>}
          </div>
        )}
        <button onClick={() => window.print()}>Print</button>
        <button onClick={newSale}>New sale</button>
      </section>
    )
  }

  const lines = order?.lines ?? []
  const balance = order ? order.total_cents - order.paid_cents : 0

  return (
    <section className="card">
      <header className="row">
        <h2>{order ? `Order ${order.number}` : 'New sale'}</h2>
        <button type="button" className="secondary" onClick={onCloseShift}>Close shift</button>
      </header>

      <form onSubmit={scan}>
        <input
          ref={scanRef} autoFocus placeholder="Scan or type a barcode…"
          value={barcode} onChange={(e) => setBarcode(e.target.value)}
        />
      </form>

      {lines.length > 0 && (
        <table className="cart">
          <tbody>
            {lines.filter((l) => !l.voided_at).map((l) => (
              <tr key={l.id}>
                <td>{l.name}</td>
                <td>{l.qty === '1.000' ? '' : l.qty}</td>
                <td className="num">{fm(l.line_total_cents)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {order && (
        <dl className="totals">
          <dt>Subtotal</dt><dd>{fm(order.subtotal_cents)}</dd>
          <dt>Tax</dt><dd>{fm(order.tax_cents)}</dd>
          <dt>Total</dt><dd className="grand">{fm(order.total_cents)}</dd>
        </dl>
      )}

      {order && phase.name === 'scanning' && (
        <button disabled={order.total_cents === 0} onClick={() => setPhase({ name: 'tender' })}>
          Pay cash — {fm(balance)}
        </button>
      )}

      {order && phase.name === 'tender' && (
        <form onSubmit={pay}>
          <label>
            Cash tendered (owed: {fm(balance)})
            <input value={tendered} onChange={(e) => setTendered(e.target.value)} inputMode="decimal" autoFocus />
          </label>
          <button type="submit">Take payment</button>
          <button type="button" className="secondary" onClick={() => setPhase({ name: 'scanning' })}>Back</button>
        </form>
      )}

      {error && <p className="error">{error}</p>}
    </section>
  )
}
```

- [ ] **Step 4: Wire App.tsx**

Replace `App.tsx`'s body: keep the health-check gate, then route by session state.

```tsx
import { useCallback, useEffect, useState } from 'react'
import { ApiError, api, tokens, type Shift } from './lib/api'
import { PinScreen, SetupScreen } from './register/SessionScreens'
import { CloseShiftScreen, OpenShiftScreen } from './register/ShiftScreens'
import { SaleScreen } from './register/SaleScreen'

type Stage =
  | { name: 'setup' }
  | { name: 'pin' }
  | { name: 'loading-shift' }
  | { name: 'open-shift' }
  | { name: 'selling'; shift: Shift }
  | { name: 'closing'; shift: Shift }

export default function App() {
  const [stage, setStage] = useState<Stage>(() =>
    !tokens.device() ? { name: 'setup' } : !tokens.staff() ? { name: 'pin' } : { name: 'loading-shift' })

  const loadShift = useCallback(async () => {
    try {
      const current = await api.currentShift()
      setStage({ name: 'selling', shift: current.shift })
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) return setStage({ name: 'open-shift' })
      if (err instanceof ApiError && err.status === 401) {
        tokens.clearStaff()
        return setStage({ name: 'pin' })
      }
      throw err
    }
  }, [])

  useEffect(() => {
    if (stage.name === 'loading-shift') void loadShift()
  }, [stage.name, loadShift])

  return (
    <main className="shell">
      <header>
        <h1>POS</h1>
        <p className="muted">Register</p>
      </header>

      {stage.name === 'setup' && <SetupScreen onDone={() => setStage({ name: 'pin' })} />}
      {stage.name === 'pin' && <PinScreen onLoggedIn={() => setStage({ name: 'loading-shift' })} />}
      {stage.name === 'loading-shift' && <p className="muted">Loading…</p>}
      {stage.name === 'open-shift' && <OpenShiftScreen onOpened={(shift) => setStage({ name: 'selling', shift })} />}
      {stage.name === 'selling' && (
        <SaleScreen onCloseShift={() => setStage({ name: 'closing', shift: stage.shift })} />
      )}
      {stage.name === 'closing' && (
        <CloseShiftScreen
          shiftId={stage.shift.id}
          onCancel={() => setStage({ name: 'selling', shift: stage.shift })}
          onClosed={() => {
            tokens.clearStaff()   // the server revoked the session at close
            setStage({ name: 'pin' })
          }}
        />
      )}
    </main>
  )
}
```

(The health check card from M0 can stay as a `stage: 'down'` guard if trivial to keep; otherwise drop it — `/health` remains curl-able and the first API failure surfaces in-flow. Keep the diff shortest.)

Append to `index.css`:

```css
.row { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
.error { color: #c0392b; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.cart, .receipt table { width: 100%; border-collapse: collapse; }
.cart td, .receipt td { padding: 0.25rem 0; border-bottom: 1px solid #eee; }
.totals { display: grid; grid-template-columns: 1fr auto; margin-top: 0.75rem; }
.totals dd.grand { font-size: 1.3rem; font-weight: 700; }
.receipt { margin: 1rem 0; padding: 1rem; border: 1px dashed #999; }
button.secondary { background: transparent; color: inherit; border: 1px solid #999; }
@media print { .shell > header, button, form { display: none; } .receipt { border: none; } }
```

- [ ] **Step 5: Verify**

Run: `cd frontend/web && npx tsc -b --force && npm test && npm run build`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add frontend/web/src
git commit -m "M3: register UI — scan, cart, cash tender, change, receipt, shift close"
```

---

### Task 15: Docs, roadmap, and the end-to-end proof

**Files:**
- Modify: `docs/03-api.md` (error table + catalog notes), `docs/06-roadmap.md` (M3 status + build notes), `CLAUDE.md` (status section)
- Test: full suites + a real sale in a browser (the milestone's "done when")

- [ ] **Step 1: Update `docs/03-api.md`**

In the error table's 409 row, extend the examples list with: `no_open_shift`, `shift_already_closed`, `shift_has_open_orders`.

In the Catalog section, add after the `updated_since` paragraph:

```markdown
> **v1 notes:** the location is taken from the enrolled register, never from
> `location_id` — a device that could choose its pricing location would be a tampering
> vector. The parameter exists for the back office (M6). `updated_since` is not yet
> implemented (`modifiers` has no `updated_at` to diff on); registers full-sync.
```

- [ ] **Step 2: Update `docs/06-roadmap.md` and `CLAUDE.md`**

Mark M3 **Status: complete** with build notes in the roadmap's established voice — write them from what actually happened during Tasks 1–14 (surprises, decisions, anything the docs predicted wrongly), not from this plan. Update `CLAUDE.md`'s Status section: M3 complete, next M4, and mention the seeder now prints device tokens.

- [ ] **Step 3: Full verification**

```bash
cd backend && ./vendor/bin/pest                      # all suites, real Postgres
cd frontend/web && npm test && npx tsc -b --force && npm run build
```

Expected: everything green (M2 had 191 backend tests; expect ~240+ now).

- [ ] **Step 4: The sale that proves the milestone**

```bash
cd infra && docker compose up -d
cd backend && php artisan migrate:fresh --seed       # note a DT device token + PIN 1111
php artisan serve                                     # terminal 1
cd frontend/web && npm run dev                        # terminal 2
```

In a browser at http://127.0.0.1:5173: paste the device token → PIN `1111` → open shift with `200.00` float → scan `012345678905` (Blue/S t-shirt, $19.99 + NYC tax = $21.76) → Pay cash, tender `25.00` → change **$3.24** → receipt shows the line, tax, and totals → New sale → Close shift, count `221.76` → variance **$0.00**, drawer reconciles.

Also verify stock moved: `docker exec pos-postgres psql -U pos -d pos -c "select qty from stock_levels sl join product_variants v on v.id = sl.variant_id where v.sku = 'TSHIRT-BLUE-S';"` → `19.000` at the selling location.

- [ ] **Step 5: Commit**

```bash
git add docs CLAUDE.md
git commit -m "M3: vertical slice complete — docs and roadmap notes"
```

---

## Self-Review

- **Spec coverage** (roadmap M3 line by line): open shift with float → Task 7; `GET /catalog` + barcode lookup → Task 11; open order + add line (snapshots + stock decrement in one transaction) → Task 8; cash payment, change, auto-close → Task 9; receipt JSON from snapshots → Task 10; register UI scan→cart→tender→change→receipt → Tasks 13–14; close shift, count, variance → Task 7. Named invariant tests from `01-architecture.md`: replayed key charges once (Task 9), concurrent last-unit sale (Task 4), closed order rejects mutation (Tasks 8/9), shift variance formula (Task 7). Split-payments-sum-exactly is covered by the partial-payments test (Task 9); penny allocation itself was M1.
- **Type consistency**: `OrderStatus` enum shared by Tasks 8/9; `NoOpenShift` defined in Task 7, consumed by 8/9; `CatalogSnapshot` property names match `CatalogResource`; `staffHeaders` helper defined once in Task 1 and used in 7–11; `ShiftCloseResult`/`CloseShiftResource` field names align (`variance_cents`, `requires_approval`).
- **Known judgment calls an executor may hit**: exact `StaffSession`/`ApiErrorEnvelope` field names (verify against M2 code, noted inline); Pest `$this`-typing on helper functions (inline if awkward); `Register::location` relation existence; `Quantity::__toString` decimal format. Each is flagged at its point of use.
