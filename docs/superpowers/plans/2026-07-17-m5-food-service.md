# M5 — Food Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A cafe can run a lunch service — open tabs against tables, courses with modifiers over an hour, transfer a tab between servers, split a check three ways — on the same order model retail uses.

**Architecture:** Everything follows the M3/M4 conventions: one route = one single-action controller = one final Action (Input DTO, owns `DB::transaction`, no HTTP knowledge); If-Match version check inside the transaction after `lockForUpdate`; every mutation audited; the whole order returned on every order mutation. Spec: `docs/superpowers/specs/2026-07-17-m5-food-service-design.md`. Schema change is confined to two `shifts` columns and one `registers` column — the order model is untouched (that's the milestone's thesis).

**Tech Stack:** Laravel 13 / PHP 8.5, PostgreSQL 18, Pest; Next.js 16 (app router) + React 19 + TanStack React Query v5 + TypeScript 7.

## Global Constraints

- **Machine-local:** Postgres is on host port **5433**. Backend tests run as `DB_PORT=5433 ./vendor/bin/pest` from `backend/`. `php artisan` reads `backend/.env` (already 5433). NEVER edit `phpunit.xml` or committed config for ports.
- **Money is integer cents** (`bigint`/`int`), quantities are milli-integer strings (`"0.500"`) on the wire. All rounding through `Money::fraction()` (via `multipliedByQuantity`/`allocate`/`allocateByRatios`). No float, ever.
- **Validation failures are 400 `validation_failed`** (malformed request); domain refusals are 409/422 with one `DomainException` subclass per code.
- **Eloquent `create()` never hydrates DB column defaults** — set every column explicitly (see `OpenOrder`).
- **`jsonb` reorders keys** — idempotency-replay assertions use `toEqual`, never `toBe`.
- Actions are `final`, never touch HTTP, take one readonly Input DTO. `tests/Arch/` enforces this mechanically — new actions must pass it unmodified.
- Frontend: type-gate is `npm run typecheck` (`tsc --noEmit`); `next build` has `ignoreBuildErrors` deliberately (TS7 incompatibility). Run both. `npm test` for unit tests.
- Commits: imperative `M5: <what>` messages, no Co-Authored-By trailer (owner policy).
- Frontend dev server runs on port 5174 (5173 is taken by another project).

## Existing surfaces you will reuse (verbatim signatures)

- `Money::fromCents(int): Money`, `->plus/minus/multipliedByQuantity(Quantity)/allocate(int $parts): array<Money>/min/max/isNegative/isPositive`, `Money::sum(array): Money`
- `Quantity::fromString('1.500')` ⇄ `->milli` (int), `Quantity::fromMilli(int)`, `->minus/plus/isPositive/equals`, `(string)$qty` formats `"1.500"`
- `StockLedger::sell(variantId, locationId, Quantity, refType, refId, userId)`, `::restock(...same...)`
- `OrderTotals::recalculate(Order $order): void` — resolves discounts, rewrites every non-voided line's `line_total_cents`/`tax_cents` (base = unit×qty + modifiers_total − discount), then order totals
- `AuditLogger::record(string $action, Model $entity, string $actorId, array $context = [], ?string $registerId = null)`
- `Shift::openFor(string $registerId): ?Shift`
- `OrderNumbers::next(Location $location, string $businessDate): string`
- Pest helpers (`tests/Pest.php`): `provisionedLocation(array $attrs = [])`, `registerAt(Location)`, `staffWithRole(Location, Roles::CASHIER|SUPERVISOR)`, `staffHeaders(Register, User): array`, factories `Order::factory()->forRegister($register)`, `ProductVariant::factory()->untracked()`, `Discount::factory()->fixed(int)`
- Permissions (all pre-existing in `App\Domain\Rbac\Permissions`): `ORDER_LINE_ADD`, `ORDER_LINE_UPDATE` (cashier tier), `ORDER_LINE_VOID` (supervisor), `ORDER_TRANSFER` (supervisor), `SHIFT_APPROVE_VARIANCE` (supervisor), `ORDER_OPEN`
- Middleware aliases: `device`, `staff`, `idempotent` (see `backend/routes/api.php`)
- Error envelope: `{ error: { code, message, details } }`; success `{ data: ... }`

---

### Task 1: M5 schema plumbing — migration, models, register mode on login, seeder

**Files:**
- Create: `backend/database/migrations/2026_07_17_000100_add_m5_columns.php`
- Create: `backend/app/Models/OrderLineModifier.php`
- Modify: `backend/app/Models/OrderLine.php` (add `modifiers()` relation; `modifiers_total_cents` + `prep_state` to `$fillable` if absent)
- Modify: `backend/app/Models/Shift.php` (fillable + casts for the two new columns)
- Modify: `backend/app/Models/Register.php` (add `mode` to `$fillable`)
- Modify: `backend/app/Models/Order.php` (add `opener()` belongsTo if absent)
- Modify: `backend/app/Actions/Auth/StaffSession.php`, `backend/app/Actions/Auth/StaffLogin.php`, `backend/app/Http/Resources/StaffSessionResource.php` (register `{id,name,mode}` in the login payload)
- Modify: `backend/app/Http/Resources/ShiftResource.php` (emit the two new fields)
- Modify: `backend/database/seeders/DatabaseSeeder.php` (Till 2 at each location becomes `mode => 'food'`)
- Test: `backend/tests/Feature/Schema/M5ColumnsTest.php`

**Interfaces:**
- Produces: `registers.mode` (`'retail'|'food'`, default `'retail'`, CHECK-constrained); `shifts.variance_approved_by/variance_approved_at` (nullable); model `OrderLineModifier` (`$timestamps = false`; fillable `order_line_id, modifier_id, name_snapshot, price_delta_cents`); `OrderLine::modifiers(): HasMany`; `Order::opener(): BelongsTo(User::class, 'opened_by')`; staff login response gains `"register": {"id","name","mode"}`; `ShiftResource` gains `variance_approved_by`, `variance_approved_at`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/Schema/M5ColumnsTest.php
declare(strict_types=1);

use App\Models\Register;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

it('defaults registers.mode to retail and enforces the check constraint', function (): void {
    $register = registerAt(provisionedLocation());
    expect($register->refresh()->mode)->toBe('retail');

    expect(fn () => DB::statement(
        "update registers set mode = 'disco' where id = ?", [$register->id]
    ))->toThrow(Illuminate\Database\QueryException::class);
});

it('stores a variance approval on shifts', function (): void {
    $location = provisionedLocation();
    $register = registerAt($location);
    $supervisor = staffWithRole($location, App\Domain\Rbac\Roles::SUPERVISOR);
    $shift = Shift::factory()->create(['register_id' => $register->id, 'closed_at' => now(), 'counted_cash_cents' => 0]);

    $shift->forceFill(['variance_approved_by' => $supervisor->id, 'variance_approved_at' => now()])->save();
    expect($shift->refresh()->variance_approved_by)->toBe($supervisor->id);
});

it('returns the register with its mode on staff login', function (): void {
    $location = provisionedLocation();
    $register = registerAt($location);
    $register->forceFill(['mode' => 'food'])->save();
    $cashier = staffWithRole($location, App\Domain\Rbac\Roles::CASHIER);
    $cashier->forceFill(['pin_hash' => bcrypt('4321'), 'pin_lookup' => App\Models\User::pinLookupHash('4321')])->save();

    $token = $register->createToken('device')->plainTextToken;
    $this->postJson('/api/v1/staff/login', ['pin' => '4321'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.register.id', $register->id)
        ->assertJsonPath('data.register.mode', 'food');
});
```

> If `Shift::factory()` or the PIN fixture differs, mirror the idiom used in
> `tests/Feature/Shifts/CloseShiftTest.php` / `tests/Feature/Auth/StaffLoginTest.php` —
> the assertion targets are what matter.

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && DB_PORT=5433 ./vendor/bin/pest tests/Feature/Schema/M5ColumnsTest.php`
Expected: FAIL — `mode` column does not exist.

- [ ] **Step 3: The migration**

```php
<?php
// backend/database/migrations/2026_07_17_000100_add_m5_columns.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Raw SQL, matching the M2 migrations: the check constraint is the point.
        DB::statement("alter table registers add column mode text not null default 'retail'");
        DB::statement("alter table registers add constraint registers_mode_check check (mode in ('retail','food'))");

        DB::statement('alter table shifts add column variance_approved_by uuid null references users(id)');
        DB::statement('alter table shifts add column variance_approved_at timestamptz null');
        // Approval implies someone approved and when — half an approval is unrepresentable.
        DB::statement('alter table shifts add constraint shifts_variance_approval_paired check ((variance_approved_by is null) = (variance_approved_at is null))');
    }

    public function down(): void
    {
        DB::statement('alter table shifts drop column variance_approved_at');
        DB::statement('alter table shifts drop column variance_approved_by');
        DB::statement('alter table registers drop constraint registers_mode_check');
        DB::statement('alter table registers drop column mode');
    }
};
```

- [ ] **Step 4: Models**

`backend/app/Models/OrderLineModifier.php` (new):

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A modifier frozen onto a line at add time — name and per-unit delta are snapshots,
 * never joined back to the live catalog (receipts must reprint identically forever).
 */
final class OrderLineModifier extends Model
{
    use HasUuids;

    protected $table = 'order_line_modifiers';

    public $timestamps = false;

    protected $fillable = ['order_line_id', 'modifier_id', 'name_snapshot', 'price_delta_cents'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }
}
```

`OrderLine.php` — add:

```php
public function modifiers(): HasMany
{
    return $this->hasMany(OrderLineModifier::class, 'order_line_id');
}
```

and ensure `modifiers_total_cents` and `prep_state` are in `$fillable`.

`Shift.php` — add `'variance_approved_by', 'variance_approved_at'` to `$fillable` and `'variance_approved_at' => 'datetime'` to `casts()`. `Register.php` — add `'mode'` to `$fillable`. `Order.php` — add:

```php
public function opener(): BelongsTo
{
    return $this->belongsTo(User::class, 'opened_by');
}
```

- [ ] **Step 5: Register on the login payload**

`StaffSession.php` gains the register:

```php
final readonly class StaffSession
{
    public function __construct(
        public User $user,
        public string $token,
        public Carbon $expiresAt,
        public Register $register,
    ) {}
}
```

In `StaffLogin.php`, the action already resolves the register (it sets the permission team
context from it); pass it into the constructor at the `return new StaffSession(...)` site.
`StaffSessionResource.php` — add to the array:

```php
'register' => [
    'id' => $session->register->id,
    'name' => $session->register->name,
    'mode' => $session->register->mode,
],
```

`ShiftResource.php` — add:

```php
'variance_approved_by' => $this->variance_approved_by,
'variance_approved_at' => $this->variance_approved_at?->toIso8601String(),
```

- [ ] **Step 6: Seeder**

In `DatabaseSeeder.php`, where registers are created (`foreach (['Till 1', 'Till 2'] as $name)`), make Till 2 a food register:

```php
$register = Register::factory()->create([
    'location_id' => $location->id,
    'name' => $name,
    'mode' => $name === 'Till 2' ? 'food' : 'retail',
]);
```

- [ ] **Step 7: Migrate and run**

Run: `cd backend && php artisan migrate && DB_PORT=5433 ./vendor/bin/pest tests/Feature/Schema/M5ColumnsTest.php`
Expected: PASS. Then the whole suite: `DB_PORT=5433 ./vendor/bin/pest` — the StaffLogin change may break existing login tests asserting the exact payload; update those assertions to include `register`.

- [ ] **Step 8: Commit**

```bash
git add -A backend && git commit -m "M5: registers.mode, shift variance-approval columns, modifier line model"
```

---

### Task 2: Extract the order-mutation preamble (M4 triage)

Every order mutation repeats: resolve the register's location → fetch the order location-scoped with `lockForUpdate` → refuse non-open status → refuse version mismatch. M5 adds four more copies; extract it first.

**Files:**
- Create: `backend/app/Domain/Orders/OpenOrderLock.php`
- Modify: `backend/app/Actions/Orders/AddLineToOrder.php`, `VoidLine.php`, `VoidOrder.php`, `ApplyDiscount.php`, `RemoveDiscount.php`, `SettleZeroOrder.php` (and `ReopenOrder.php` only if it has the same open-status preamble — it likely differs, it reopens closed orders; leave it if so)
- Test: existing suites prove the refactor (no new tests)

**Interfaces:**
- Produces: `OpenOrderLock::acquire(string $orderId, string $registerId, int $expectedVersion): Order` — returns the locked, verified-open, version-checked Order; throws `OrderClosed` / `OrderVersionConflict` / `ModelNotFoundException` exactly as the inline copies did. MUST be called inside a `DB::transaction`.

- [ ] **Step 1: Write the helper**

```php
<?php
// backend/app/Domain/Orders/OpenOrderLock.php
declare(strict_types=1);

namespace App\Domain\Orders;

use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Register;

/**
 * The preamble every order mutation shares: location-scope the fetch (another
 * location's order is a 404, not a bypass — docs/05-rbac.md), lock the row, refuse
 * a non-open order, refuse a stale client. Call only inside DB::transaction.
 */
final class OpenOrderLock
{
    public function acquire(string $orderId, string $registerId, int $expectedVersion): Order
    {
        $locationId = Register::findOrFail($registerId)->location_id;

        $order = Order::whereKey($orderId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->status !== OrderStatus::Open) {
            throw new OrderClosed($order->id, $order->status->value);
        }
        if ($order->version !== $expectedVersion) {
            throw new OrderVersionConflict($order->id, $expectedVersion, $order->version);
        }

        return $order;
    }
}
```

- [ ] **Step 2: Retrofit the M4 actions**

In each listed action, inject `private readonly OpenOrderLock $lock` in the constructor and replace the four-part preamble with:

```php
$order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);
```

Keep any action-specific checks that followed the preamble (e.g. `VoidOrder`'s `paid_cents > 0` refusal) exactly where they were. Where the action needs `$locationId` afterwards, use `$order->location_id`.

- [ ] **Step 3: Run the full backend suite**

Run: `cd backend && DB_PORT=5433 ./vendor/bin/pest`
Expected: PASS — 350 tests, unchanged behavior. Any failure means the extraction dropped a check; fix the extraction, not the test.

- [ ] **Step 4: Commit**

```bash
git add -A backend && git commit -m "M5: extract OpenOrderLock — the shared order-mutation preamble"
```

---

### Task 3: Modifiers end-to-end in AddLineToOrder

**Files:**
- Modify: `backend/app/Actions/Orders/AddLineInput.php`, `AddLineToOrder.php`
- Modify: `backend/app/Http/Requests/Orders/AddLineRequest.php` (drop the `prohibited`, validate the array)
- Create: `backend/app/Exceptions/Domain/ModifierNotApplicable.php`, `ModifierGroupRequired.php`, `LineTotalNegative.php`
- Modify: `backend/app/Http/Resources/OrderLineResource.php` (modifiers + prep_state), `backend/app/Actions/Orders/GetReceipt.php` + its resource (modifiers under lines)
- Test: `backend/tests/Feature/Orders/AddLineModifiersTest.php`

**Interfaces:**
- Consumes: `OpenOrderLock` (Task 2), `OrderLineModifier` (Task 1).
- Produces: `POST /orders/{id}/lines` accepts `"modifiers": ["<modifier_id>", ...]` (repeats legal); line payloads gain `"modifiers": [{ "name", "price_delta_cents" }]` and `"prep_state"`; receipt lines gain the same `modifiers` array; error codes `modifier_not_applicable` (422), `modifier_group_required` (422), `line_total_negative` (422). `AddLineInput` gains `public array $modifierIds = []`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/Orders/AddLineModifiersTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);

    $this->latte = ProductVariant::factory()->untracked()->create(['price_cents' => 500, 'tax_rate_id' => null]);
    $product = Product::findOrFail($this->latte->product_id);

    $this->milk = ModifierGroup::create(['name' => 'Milk', 'min_select' => 1, 'max_select' => 1]);
    $this->oat = Modifier::create(['group_id' => $this->milk->id, 'name' => 'Oat', 'price_delta_cents' => 60, 'position' => 0, 'is_active' => true]);
    $this->whole = Modifier::create(['group_id' => $this->milk->id, 'name' => 'Whole', 'price_delta_cents' => 0, 'position' => 1, 'is_active' => true]);

    $this->extras = ModifierGroup::create(['name' => 'Extras', 'min_select' => 0, 'max_select' => 3]);
    $this->shot = Modifier::create(['group_id' => $this->extras->id, 'name' => 'Extra shot', 'price_delta_cents' => 75, 'position' => 0, 'is_active' => true]);

    $product->modifierGroups()->attach([$this->milk->id => ['position' => 0], $this->extras->id => ['position' => 1]]);

    $this->headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];
});

function addLine(object $t, array $body, array $headers = null): Illuminate\Testing\TestResponse
{
    return $t->postJson("/api/v1/orders/{$t->order->id}/lines",
        ['variant_id' => $t->latte->id, 'qty' => '1'] + $body, $headers ?? $t->headers);
}

it('snapshots modifiers and prices per unit times qty', function (): void {
    // 2 lattes, oat (+60) and a double extra shot (+75 ×2, repeats legal): per unit +210.
    $response = addLine($this, ['qty' => '2', 'modifiers' => [$this->oat->id, $this->shot->id, $this->shot->id]]);
    $response->assertCreated()
        ->assertJsonPath('data.line.modifiers.0.name', 'Oat')
        // (500×2) + (210×2) = 1420
        ->assertJsonPath('data.line.line_total_cents', 1420)
        ->assertJsonPath('data.order.total_cents', 1420);
    expect($response->json('data.line.modifiers'))->toHaveCount(3);
});

it('rounds a fractional qty through the one primitive', function (): void {
    // 0.500 × (500 + 75) = 287.5 → 288 (half away from zero)
    addLine($this, ['qty' => '0.500', 'modifiers' => [$this->whole->id, $this->shot->id]])
        ->assertCreated()
        ->assertJsonPath('data.line.line_total_cents', 288);
});

it('refuses a missing required group', function (): void {
    addLine($this, ['modifiers' => []])
        ->assertStatus(422)->assertJsonPath('error.code', 'modifier_group_required');
});

it('refuses overshooting max_select', function (): void {
    addLine($this, ['modifiers' => [$this->oat->id, $this->whole->id]])
        ->assertStatus(422)->assertJsonPath('error.code', 'modifier_group_required');
});

it('refuses a modifier from a group not attached to the product', function (): void {
    $rogueGroup = ModifierGroup::create(['name' => 'Rogue', 'min_select' => 0, 'max_select' => null]);
    $rogue = Modifier::create(['group_id' => $rogueGroup->id, 'name' => 'Rogue mod', 'price_delta_cents' => 0, 'position' => 0, 'is_active' => true]);
    addLine($this, ['modifiers' => [$this->oat->id, $rogue->id]])
        ->assertStatus(422)->assertJsonPath('error.code', 'modifier_not_applicable');
});

it('refuses a line whose resolved total would go negative', function (): void {
    $discount = Modifier::create(['group_id' => $this->extras->id, 'name' => 'Comp', 'price_delta_cents' => -600, 'position' => 1, 'is_active' => true]);
    addLine($this, ['modifiers' => [$this->oat->id, $discount->id]])
        ->assertStatus(422)->assertJsonPath('error.code', 'line_total_negative');
});

it('renders modifiers on the receipt from snapshots', function (): void {
    $order = addLine($this, ['modifiers' => [$this->oat->id]])->json('data.order');
    // rename after the fact — receipt must not notice
    $this->oat->update(['name' => 'RENAMED']);
    $this->postJson("/api/v1/orders/{$order['id']}/payments",
        ['driver' => 'cash', 'amount_cents' => $order['total_cents'], 'tendered_cents' => $order['total_cents']],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $order['version'], 'Idempotency-Key' => (string) Illuminate\Support\Str::uuid()],
    )->assertCreated();
    $this->getJson("/api/v1/orders/{$order['id']}/receipt", staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.lines.0.modifiers.0.name', 'Oat')
        ->assertJsonPath('data.lines.0.modifiers.0.price_delta_cents', 60);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `cd backend && DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/AddLineModifiersTest.php`
Expected: FAIL — `modifiers` is `prohibited` (400), then missing columns/relations as you progress.

- [ ] **Step 3: Exceptions**

Three new `DomainException` subclasses, exactly in the `OrderNotZero` mold (constructor stashes details, `errorCode()`, `httpStatus(): 422`, `details()`):

```php
<?php
// backend/app/Exceptions/Domain/ModifierNotApplicable.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

final class ModifierNotApplicable extends DomainException
{
    public function __construct(private readonly string $modifierId)
    {
        parent::__construct('This modifier does not apply to that product (or is inactive).');
    }

    public function errorCode(): string
    {
        return 'modifier_not_applicable';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['modifier_id' => $this->modifierId];
    }
}
```

```php
<?php
// backend/app/Exceptions/Domain/ModifierGroupRequired.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

final class ModifierGroupRequired extends DomainException
{
    public function __construct(
        private readonly string $groupId,
        private readonly string $groupName,
        private readonly int $min,
        private readonly ?int $max,
        private readonly int $selected,
    ) {
        parent::__construct("Modifier group \"{$groupName}\" needs between {$min} and ".($max ?? '∞')." selections; got {$selected}.");
    }

    public function errorCode(): string
    {
        return 'modifier_group_required';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['group_id' => $this->groupId, 'min_select' => $this->min, 'max_select' => $this->max, 'selected' => $this->selected];
    }
}
```

```php
<?php
// backend/app/Exceptions/Domain/LineTotalNegative.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

final class LineTotalNegative extends DomainException
{
    public function __construct(private readonly int $resolvedCents)
    {
        parent::__construct('Modifiers would make this line total negative.');
    }

    public function errorCode(): string
    {
        return 'line_total_negative';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['resolved_cents' => $this->resolvedCents];
    }
}
```

- [ ] **Step 4: Request + Input**

`AddLineRequest.php` rules — replace the `prohibited` line:

```php
'modifiers' => ['sometimes', 'array', 'max:20'],
'modifiers.*' => ['uuid'],
```

and in `toInput()` add `modifierIds: array_values($this->input('modifiers', []))`. `AddLineInput.php` gains:

```php
/** @var list<string> ids as selected; repeats are meaningful ("double bacon") */
public array $modifierIds = [],
```

- [ ] **Step 5: The action**

In `AddLineToOrder::execute`, after `$variant`/`$price` resolve and before the line create, validate and price the selection:

```php
$selection = $this->resolveModifiers($variant, $in->modifierIds);   // list<Modifier>, repeats preserved
$perUnitDelta = Money::fromCents(array_sum(array_map(fn (Modifier $m) => $m->price_delta_cents, $selection)));

$qty = Quantity::fromString($in->qty);
$modifiersTotal = $perUnitDelta->multipliedByQuantity($qty);

$resolved = $price->multipliedByQuantity($qty)->plus($modifiersTotal);
if ($resolved->isNegative()) {
    throw new LineTotalNegative($resolved->cents);
}
```

The line create gains `'modifiers_total_cents' => $modifiersTotal->cents`, then right after it:

```php
foreach ($selection as $modifier) {
    $line->modifiers()->create([
        'modifier_id' => $modifier->id,
        'name_snapshot' => $modifier->name,
        'price_delta_cents' => $modifier->price_delta_cents,
    ]);
}
```

And the private resolver (whole method):

```php
/**
 * @param list<string> $modifierIds
 * @return list<\App\Models\Modifier> in selection order, repeats preserved
 */
private function resolveModifiers(ProductVariant $variant, array $modifierIds): array
{
    $groups = $variant->product->modifierGroups()->with(['modifiers' => fn ($q) => $q->where('is_active', true)])->get();
    if ($modifierIds === [] && $groups->every(fn ($g) => $g->min_select === 0)) {
        return [];
    }

    $byId = $groups->flatMap->modifiers->keyBy('id');

    $selection = [];
    foreach ($modifierIds as $id) {
        $selection[] = $byId[$id] ?? throw new ModifierNotApplicable($id);
    }

    $counts = array_count_values($modifierIds);
    foreach ($groups as $group) {
        $selected = $group->modifiers->sum(fn ($m) => $counts[$m->id] ?? 0);
        if ($selected < $group->min_select || ($group->max_select !== null && $selected > $group->max_select)) {
            throw new ModifierGroupRequired($group->id, $group->name, $group->min_select, $group->max_select, $selected);
        }
    }

    return $selection;
}
```

(If `Product::modifierGroups()` or `ModifierGroup::modifiers()` relations don't exist yet, add them — the seeder already calls `modifierGroups()->attach`, so the Product side exists.)

- [ ] **Step 6: Resources**

`OrderLineResource.php` — add:

```php
'prep_state' => $this->prep_state,
'modifiers' => $this->modifiers()->get()->map(fn ($m) => [
    'name' => $m->name_snapshot,
    'price_delta_cents' => $m->price_delta_cents,
])->all(),
```

(Simple direct read, not `whenLoaded` — modifier rows per line are tiny and the register needs them on every envelope.) Receipt: in `GetReceipt.php`/its resource, each line gains the same `modifiers` array from `$line->modifiers` snapshots.

- [ ] **Step 7: Run, then full suite**

Run: `cd backend && DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/AddLineModifiersTest.php && DB_PORT=5433 ./vendor/bin/pest`
Expected: all PASS (existing add-line tests keep passing — no modifiers means empty selection and zero delta).

- [ ] **Step 8: Commit**

```bash
git add -A backend && git commit -m "M5: modifiers end-to-end — validation, per-unit pricing, snapshots, receipt"
```

---

### Task 4: UpdateLineQty — PATCH a line's quantity

**Files:**
- Create: `backend/app/Actions/Orders/UpdateLineQty.php`, `UpdateLineQtyInput.php`
- Create: `backend/app/Http/Requests/Orders/UpdateLineQtyRequest.php`
- Create: `backend/app/Http/Controllers/Orders/UpdateLineQtyController.php`
- Create: `backend/app/Exceptions/Domain/FiredLineRequiresSupervisor.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Orders/UpdateLineQtyTest.php`

**Interfaces:**
- Consumes: `OpenOrderLock`, `StockLedger::sell/restock`, `OrderTotals::recalculate`, `LineAlreadyVoided` (existing).
- Produces: `PATCH /api/v1/orders/{order}/lines/{line}` with `{ "qty": "3.000" }`, If-Match, permission `ORDER_LINE_UPDATE`; returns `{ order, line }` (same envelope as add-line, reuse `AddLineResource`). Decreasing a fired line (`prep_state` in `in_progress|ready`) without `ORDER_LINE_VOID` → 403 `forbidden`. Route name `orders.lines.update`. No `Idempotency-Key` — an absolute qty target is naturally idempotent.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/Orders/UpdateLineQtyTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLevel;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $this->variant = ProductVariant::factory()->create(['price_cents' => 300, 'tax_rate_id' => null, 'track_inventory' => true]);
    app(App\Domain\Stock\StockLedger::class)->receive($this->variant->id, $this->location->id, App\Domain\Money\Quantity::fromString('10'), null, 'seed');

    $this->line = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, registerId: $this->register->id, variantId: $this->variant->id,
        qty: '2', expectedVersion: 0, actorId: $this->cashier->id,
    ));
});

function stockQty(object $t): string
{
    return StockLevel::where('variant_id', $t->variant->id)->where('location_id', $t->location->id)->value('qty');
}

it('raises qty, decrementing further stock', function (): void {
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '5'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('data.line.qty', '5.000')
        ->assertJsonPath('data.order.total_cents', 1500);
    expect(stockQty($this))->toBe('5.000');   // 10 − 2 − 3 more
});

it('lowers qty, restocking the difference', function (): void {
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '0.500'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('data.order.total_cents', 150);
    expect(stockQty($this))->toBe('9.500');
});

it('refuses insufficient stock on a raise', function (): void {
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '99'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertStatus(409)->assertJsonPath('error.code', 'insufficient_stock');
});

it('needs a supervisor to shrink a fired line', function (): void {
    $this->line->forceFill(['prep_state' => 'in_progress'])->save();
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'];
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '1'], $headers)
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    // raising the same fired line is normal service (another round)
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '3'], $headers)
        ->assertOk();
    // and a supervisor may shrink it
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '1'],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '2'])
        ->assertOk();
});

it('refuses a voided line and a stale version', function (): void {
    $this->line->forceFill(['voided_at' => now()])->save();
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '1'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertStatus(422)->assertJsonPath('error.code', 'line_already_voided');
});

it('rescales modifier money from frozen snapshots', function (): void {
    App\Models\OrderLineModifier::create([
        'order_line_id' => $this->line->id, 'modifier_id' => null,
        'name_snapshot' => 'Extra shot', 'price_delta_cents' => 75,
    ]);
    $this->line->forceFill(['modifiers_total_cents' => 150])->save();   // 75 × qty 2
    app(App\Domain\Pricing\OrderTotals::class)->recalculate($this->order->refresh());

    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '3'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertOk()
        // (300 + 75) × 3
        ->assertJsonPath('data.line.line_total_cents', 1125);
});
```

> `modifier_id` nullable in that last test: if the FK refuses null, create a real Modifier
> row instead — the assertion is about the rescale, not the FK.

- [ ] **Step 2: Run to verify failure**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/UpdateLineQtyTest.php`
Expected: FAIL — 404, route not defined.

- [ ] **Step 3: Exception, Input, Request, Controller, route**

`FiredLineRequiresSupervisor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class FiredLineRequiresSupervisor extends DomainException
{
    public function __construct(private readonly string $lineId)
    {
        parent::__construct('Reducing a line already fired to the kitchen needs a supervisor.');
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['line_id' => $this->lineId];
    }
}
```

`UpdateLineQtyInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class UpdateLineQtyInput
{
    public function __construct(
        public string $orderId,
        public string $lineId,
        public string $registerId,
        public string $qty,               // numeric string; absolute target, not a delta
        public int $expectedVersion,
        public string $actorId,
        public bool $actorMayVoidLines,   // evaluated in the FormRequest; the action has no HTTP/user access
    ) {}
}
```

`UpdateLineQtyRequest.php` (authorize `ORDER_LINE_UPDATE`; same qty regex as AddLine; `if_match` merge idiom copied from `AddLineRequest`):

```php
public function toInput(): UpdateLineQtyInput
{
    return new UpdateLineQtyInput(
        orderId: (string) $this->route('order'),
        lineId: (string) $this->route('line'),
        registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
        qty: $this->string('qty')->toString(),
        expectedVersion: (int) $this->header('If-Match'),
        actorId: $this->user()->id,
        actorMayVoidLines: $this->user()->can(Permissions::ORDER_LINE_VOID),
    );
}
```

`UpdateLineQtyController.php` mirrors `AddLineController` (returns `AddLineResource`, status 200). Route, next to the other line routes:

```php
Route::patch('/orders/{order}/lines/{line}', UpdateLineQtyController::class)
    ->name('orders.lines.update');
```

- [ ] **Step 4: The action**

```php
<?php
// backend/app/Actions/Orders/UpdateLineQty.php
declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Money;
use App\Domain\Money\Quantity;
use App\Domain\Orders\OpenOrderLock;
use App\Domain\Pricing\OrderTotals;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\FiredLineRequiresSupervisor;
use App\Exceptions\Domain\LineAlreadyVoided;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Sets a line's absolute quantity. The stock ledger sees only the delta; the money
 * columns rescale from the line's own frozen snapshots — the live catalog is never
 * consulted after add (docs/02-data-model.md). Shrinking a fired line is the same
 * fraud surface as voiding a sent line, and takes the same permission.
 */
final class UpdateLineQty
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly StockLedger $stock,
        private readonly OrderTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(UpdateLineQtyInput $in): OrderLine
    {
        return DB::transaction(function () use ($in): OrderLine {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            /** @var OrderLine $line */
            $line = $order->lines()->whereKey($in->lineId)->firstOrFail();
            if ($line->voided_at !== null) {
                throw new LineAlreadyVoided($line->id);
            }

            $old = Quantity::fromString($line->qty);
            $new = Quantity::fromString($in->qty);
            $delta = $new->minus($old);

            if ($delta->isNegative() && in_array($line->prep_state, ['in_progress', 'ready'], true) && ! $in->actorMayVoidLines) {
                throw new FiredLineRequiresSupervisor($line->id);
            }

            if (! $delta->isZero()) {
                $variant = ProductVariant::withTrashed()->find($line->variant_id);
                if ($variant !== null && $variant->track_inventory) {
                    $delta->isPositive()
                        ? $this->stock->sell($variant->id, $order->location_id, $delta, 'order_line', $line->id, $in->actorId)
                        : $this->stock->restock($variant->id, $order->location_id, $delta->negated(), 'order_line', $line->id, $in->actorId);
                }
            }

            $perUnitDelta = Money::fromCents((int) $line->modifiers()->sum('price_delta_cents'));

            $line->forceFill([
                'qty' => (string) $new,
                'modifiers_total_cents' => $perUnitDelta->multipliedByQuantity($new)->cents,
            ])->save();

            $this->totals->recalculate($order);
            $order->forceFill(['version' => $order->version + 1])->save();

            $this->audit->record('order.line.qty', $line, $in->actorId, [
                'order_id' => $order->id, 'from' => (string) $old, 'to' => (string) $new,
            ], registerId: $in->registerId);

            return $line->refresh()->setRelation('order', $order->fresh(['lines', 'discounts']));
        });
    }
}
```

- [ ] **Step 5: Run, then full suite**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/UpdateLineQtyTest.php && DB_PORT=5433 ./vendor/bin/pest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A backend && git commit -m "M5: PATCH line qty — stock delta, snapshot rescale, fired-line supervisor gate"
```

---

### Task 5: SetTableRef, the floor-view list payload, and the voided-line discount refusal

**Files:**
- Create: `backend/app/Actions/Orders/SetTableRef.php`, `SetTableRefInput.php`
- Create: `backend/app/Http/Requests/Orders/SetTableRefRequest.php`
- Create: `backend/app/Http/Controllers/Orders/SetTableRefController.php`
- Modify: `backend/routes/api.php` (`PATCH /orders/{order}` → `orders.update`)
- Modify: `backend/app/Actions/Orders/ListOrders.php` (`->with(['lines', 'discounts', 'opener'])`)
- Modify: `backend/app/Http/Resources/OrderResource.php` (`opened_at`, `opened_by_name`, `due_cents`)
- Modify: `backend/app/Actions/Orders/ApplyDiscount.php` (refuse a voided target line)
- Test: `backend/tests/Feature/Orders/SetTableRefTest.php`, additions to `tests/Feature/Orders/ApplyDiscountTest.php`

**Interfaces:**
- Produces: `PATCH /api/v1/orders/{order}` `{ "table_ref": "T12" | null }`, If-Match, permission `ORDER_OPEN` → `{ order }`; `OrderResource` gains `"opened_at"` (ISO-8601), `"opened_by_name"` (when `opener` loaded), `"due_cents"` (= `max(0, total_cents - paid_cents)`, server-computed); `ApplyDiscount` with a voided `order_line_id` → 422 `line_already_voided`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/Orders/SetTableRefTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Order;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
});

it('sets and clears the table ref', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];
    $this->patchJson("/api/v1/orders/{$this->order->id}", ['table_ref' => 'T12'], $headers)
        ->assertOk()->assertJsonPath('data.order.table_ref', 'T12')->assertJsonPath('data.order.version', 1);

    $this->patchJson("/api/v1/orders/{$this->order->id}", ['table_ref' => null],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertOk()->assertJsonPath('data.order.table_ref', null);

    $this->assertDatabaseHas('audit_log', ['action' => 'order.table_ref', 'entity_id' => $this->order->id]);
});

it('feeds the floor view: open orders carry opener name, opened_at and due_cents', function (): void {
    $this->order->forceFill(['table_ref' => 'T5', 'total_cents' => 900, 'paid_cents' => 400])->save();
    $this->getJson('/api/v1/orders?status=open', staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.orders.0.table_ref', 'T5')
        ->assertJsonPath('data.orders.0.opened_by_name', $this->cashier->name)
        ->assertJsonPath('data.orders.0.due_cents', 500);
});
```

And in `ApplyDiscountTest.php` add:

```php
it('refuses a line discount aimed at a voided line', function (): void {
    // build an order with one line via AddLineToOrder (idiom already in this file),
    // void the line, then apply a line-scoped discount targeting it:
    // expect 422 error.code line_already_voided
});
```

(Write it fully using this file's existing `beforeEach` fixtures — it already constructs lines and line-scoped discounts; copy the nearest existing `it()` and change the target line to a voided one.)

- [ ] **Step 2: Run to verify failure**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/SetTableRefTest.php`
Expected: FAIL — 404 (route not defined).

- [ ] **Step 3: Implement**

`SetTableRefInput.php`: readonly DTO `{ orderId, registerId, ?string tableRef, int expectedVersion, string actorId }`.

`SetTableRefRequest.php`: authorize `Permissions::ORDER_OPEN`; rules `['table_ref' => ['nullable', 'string', 'max:20'], 'if_match' => ['required', 'integer', 'min:0']]` with the `prepareForValidation` If-Match merge idiom; `toInput()` uses `$this->input('table_ref')` (NOT `->string()` — null must stay null).

`SetTableRef.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OpenOrderLock;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/** Moves a party between tables. Open orders only; the ref is wayfinding, not money. */
final class SetTableRef
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(SetTableRefInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            $from = $order->table_ref;
            $order->forceFill(['table_ref' => $in->tableRef, 'version' => $order->version + 1])->save();

            $this->audit->record('order.table_ref', $order, $in->actorId, [
                'from' => $from, 'to' => $in->tableRef,
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
```

`SetTableRefController.php` mirrors the other order controllers, wrapping in the same `{ order }` resource used by `VoidOrderController` (find its resource class and reuse it). Route:

```php
Route::patch('/orders/{order}', SetTableRefController::class)->name('orders.update');
```

**Order matters:** register this BEFORE `GET /orders/{order}` in the file for readability, and note `PATCH` vs `GET` cannot collide.

`OrderResource.php` — add:

```php
'opened_at' => $this->opened_at?->toIso8601String(),
'opened_by_name' => $this->whenLoaded('opener', fn () => $this->opener->name),
'due_cents' => max(0, $this->total_cents - $this->paid_cents),
```

`ListOrders.php` — change the eager load to `->with(['lines', 'discounts', 'opener'])`.

`ApplyDiscount.php` — where the target line is fetched (line-scoped path), add:

```php
if ($line->voided_at !== null) {
    throw new LineAlreadyVoided($line->id);
}
```

- [ ] **Step 4: Run, then full suite**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/SetTableRefTest.php tests/Feature/Orders/ApplyDiscountTest.php && DB_PORT=5433 ./vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A backend && git commit -m "M5: table_ref PATCH, floor-view payload (opener, due_cents), voided-line discount refusal"
```

---

### Task 6: SetLinePrepState — held → fired → ready

**Files:**
- Create: `backend/app/Actions/Orders/SetLinePrepState.php`, `SetLinePrepStateInput.php`
- Create: `backend/app/Http/Requests/Orders/SetLinePrepStateRequest.php`
- Create: `backend/app/Http/Controllers/Orders/SetLinePrepStateController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Orders/SetLinePrepStateTest.php`

**Interfaces:**
- Produces: `PATCH /api/v1/orders/{order}/lines/{line}/prep` `{ "state": "pending"|"in_progress"|"ready" }`, permission `ORDER_LINE_UPDATE`, **no If-Match and no version bump** (prep is operational, not financial — concurrent tender must not be invalidated by the kitchen). Returns `{ order, line }` via `AddLineResource`. Route name `orders.lines.prep`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/Orders/SetLinePrepStateTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\ProductVariant;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 500, 'tax_rate_id' => null]);
    $this->line = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, registerId: $this->register->id, variantId: $variant->id,
        qty: '1', expectedVersion: 0, actorId: $this->cashier->id,
    ));
});

it('fires and readies a line without bumping the order version', function (): void {
    $headers = staffHeaders($this->register, $this->cashier);
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}/prep", ['state' => 'in_progress'], $headers)
        ->assertOk()
        ->assertJsonPath('data.line.prep_state', 'in_progress')
        ->assertJsonPath('data.order.version', 1);   // still 1 from the add — prep did not bump

    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}/prep", ['state' => 'ready'], $headers)
        ->assertOk()->assertJsonPath('data.line.prep_state', 'ready');

    $this->assertDatabaseHas('audit_log', ['action' => 'order.line.prep', 'entity_id' => $this->line->id]);
});

it('rejects an unknown state as validation, and a voided line as domain refusal', function (): void {
    $headers = staffHeaders($this->register, $this->cashier);
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}/prep", ['state' => 'burnt'], $headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    $this->line->forceFill(['voided_at' => now()])->save();
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}/prep", ['state' => 'ready'], $headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'line_already_voided');
});
```

- [ ] **Step 2: Run to verify failure**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/SetLinePrepStateTest.php`
Expected: FAIL — 404.

- [ ] **Step 3: Implement**

Input DTO `{ orderId, lineId, registerId, state, actorId }`. Request: authorize `ORDER_LINE_UPDATE`; rules `['state' => ['required', 'in:pending,in_progress,ready']]`. Action — note it locks the *line's order* row location-scoped but does NOT use `OpenOrderLock` (no version check wanted, and a closed order's lines may still be marked ready while the kitchen finishes):

```php
<?php
// backend/app/Actions/Orders/SetLinePrepState.php
declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\LineAlreadyVoided;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * The coursing verbs, mapped from industry practice (spec): pending = held,
 * in_progress = fired, ready = on the pass. Deliberately no If-Match and no version
 * bump — the kitchen marking food ready must never invalidate the till mid-tender.
 */
final class SetLinePrepState
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(SetLinePrepStateInput $in): OrderLine
    {
        return DB::transaction(function () use ($in): OrderLine {
            $locationId = Register::findOrFail($in->registerId)->location_id;
            $order = Order::whereKey($in->orderId)->where('location_id', $locationId)->firstOrFail();

            /** @var OrderLine $line */
            $line = $order->lines()->whereKey($in->lineId)->firstOrFail();
            if ($line->voided_at !== null) {
                throw new LineAlreadyVoided($line->id);
            }

            $from = $line->prep_state;
            $line->forceFill(['prep_state' => $in->state])->save();

            $this->audit->record('order.line.prep', $line, $in->actorId, [
                'order_id' => $order->id, 'from' => $from, 'to' => $in->state,
            ], registerId: $in->registerId);

            return $line->refresh()->setRelation('order', $order->fresh(['lines', 'discounts']));
        });
    }
}
```

Controller mirrors `AddLineController` with 200. Route:

```php
Route::patch('/orders/{order}/lines/{line}/prep', SetLinePrepStateController::class)
    ->name('orders.lines.prep');
```

Register it ABOVE `PATCH /orders/{order}/lines/{line}` so `/prep` isn't captured by the `{line}` route first.

- [ ] **Step 4: Run, then full suite; Commit**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/SetLinePrepStateTest.php && DB_PORT=5433 ./vendor/bin/pest`
Expected: PASS.

```bash
git add -A backend && git commit -m "M5: line prep state — held/fired/ready without touching the money"
```

---

### Task 7: TransferOrder — push a tab to another drawer

**Files:**
- Create: `backend/app/Actions/Orders/TransferOrder.php`, `TransferOrderInput.php`
- Create: `backend/app/Http/Requests/Orders/TransferOrderRequest.php`
- Create: `backend/app/Http/Controllers/Orders/TransferOrderController.php`
- Create: `backend/app/Exceptions/Domain/TransferTargetNoShift.php`, `TransferSameShift.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Orders/TransferOrderTest.php`

**Interfaces:**
- Consumes: `OpenOrderLock`, `Shift::openFor`.
- Produces: `POST /api/v1/orders/{order}/transfer` `{ "register_id": "..." }`, If-Match, permission `ORDER_TRANSFER` → `{ order }` (its `register_id`/`shift_id` now the target's). Errors: 422 `transfer_target_no_shift`, 422 `transfer_same_shift`; cross-location target → 404 `not_found`. Route name `orders.transfer`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/Orders/TransferOrderTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->target = registerAt($this->location);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id, 'table_ref' => 'T7']);
    $this->targetShift = Shift::factory()->create(['register_id' => $this->target->id, 'opened_by' => $this->supervisor->id]);
});

it('moves the tab to the target drawer, keeping opened_by as history', function (): void {
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $this->target->id],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '0'])
        ->assertOk()
        ->assertJsonPath('data.order.register_id', $this->target->id)
        ->assertJsonPath('data.order.version', 1);

    $order = Order::findOrFail($this->order->id);
    expect($order->shift_id)->toBe($this->targetShift->id)
        ->and($order->opened_by)->toBe($this->cashier->id);
    $this->assertDatabaseHas('audit_log', ['action' => 'order.transfer', 'entity_id' => $this->order->id]);
});

it('is supervisor-tier', function (): void {
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $this->target->id],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'])
        ->assertStatus(403);
});

it('refuses a target with no open shift', function (): void {
    $this->targetShift->forceFill(['closed_at' => now(), 'counted_cash_cents' => 0])->save();
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $this->target->id],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '0'])
        ->assertStatus(422)->assertJsonPath('error.code', 'transfer_target_no_shift');
});

it('refuses transferring to the shift the order is already on', function (): void {
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $this->register->id],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '0'])
        ->assertStatus(422)->assertJsonPath('error.code', 'transfer_same_shift');
});

it('404s a target register at another location', function (): void {
    $elsewhere = registerAt(provisionedLocation());
    Shift::factory()->create(['register_id' => $elsewhere->id]);
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $elsewhere->id],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '0'])
        ->assertNotFound();
});
```

> `Order::factory()->forRegister()` opens (or reuses) an open shift on `$this->register`,
> so the same-shift case is real. If `Shift::factory()` lacks defaults for
> `opening_float_cents`, set it explicitly (`['opening_float_cents' => 0]`).

- [ ] **Step 2: Run to verify failure**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/TransferOrderTest.php`
Expected: FAIL — 404.

- [ ] **Step 3: Implement**

Exceptions (`OrderNotZero` mold): `TransferTargetNoShift` (422, details `register_id`), `TransferSameShift` (422, details `shift_id`). Input DTO `{ orderId, registerId, targetRegisterId, expectedVersion, actorId }`. Request: authorize `Permissions::ORDER_TRANSFER`; rules `['register_id' => ['required', 'uuid'], 'if_match' => [...]]`.

```php
<?php
// backend/app/Actions/Orders/TransferOrder.php
declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OpenOrderLock;
use App\Exceptions\Domain\TransferSameShift;
use App\Exceptions\Domain\TransferTargetNoShift;
use App\Models\Order;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Hands a tab to another drawer — the push model the industry uses (spec), expressed
 * in our accountability unit: the receiving register's open shift. A tab cannot
 * outlive the drawer accountable for it (docs/03-api.md), so shift close says
 * "transfer these first" and this is the verb it means. opened_by is history and
 * never changes; payments carry their own shift_id, so money already taken stays
 * attributed to the drawer that physically took it.
 */
final class TransferOrder
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(TransferOrderInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            $target = Register::where('id', $in->targetRegisterId)
                ->where('location_id', $order->location_id)
                ->where('is_active', true)
                ->firstOrFail();

            $targetShift = Shift::openFor($target->id) ?? throw new TransferTargetNoShift($target->id);
            if ($targetShift->id === $order->shift_id) {
                throw new TransferSameShift($targetShift->id);
            }

            $from = ['register_id' => $order->register_id, 'shift_id' => $order->shift_id];
            $order->forceFill([
                'register_id' => $target->id,
                'shift_id' => $targetShift->id,
                'version' => $order->version + 1,
            ])->save();

            $this->audit->record('order.transfer', $order, $in->actorId, [
                'from' => $from,
                'to' => ['register_id' => $target->id, 'shift_id' => $targetShift->id],
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
```

Controller mirrors `VoidOrderController` (same `{ order }` resource, 200). Route:

```php
Route::post('/orders/{order}/transfer', TransferOrderController::class)->name('orders.transfer');
```

- [ ] **Step 4: Run, then full suite; Commit**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/TransferOrderTest.php && DB_PORT=5433 ./vendor/bin/pest`
Expected: PASS.

```bash
git add -A backend && git commit -m "M5: transfer a tab between drawers — push model, target must have an open shift"
```

### Task 8: SplitOrder — split evenly into N checks

The money-critical action of the milestone. Read the spec section "SplitOrder semantics" before starting.

**Files:**
- Create: `backend/app/Actions/Orders/SplitOrder.php`, `SplitOrderInput.php`
- Create: `backend/app/Http/Requests/Orders/SplitOrderRequest.php`
- Create: `backend/app/Http/Controllers/Orders/SplitOrderController.php`
- Create: `backend/app/Http/Resources/SplitOrderResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Orders/SplitOrderTest.php`

**Interfaces:**
- Consumes: `OpenOrderLock`, `OrderNumbers::next`, `Money::allocate(int)`, `OrderHasPayments` (existing).
- Produces: `POST /api/v1/orders/{order}/split` `{ "ways": 3 }`, If-Match + `idempotent` middleware, permission `ORDER_OPEN` → 201 `{ "orders": [child OrderResource, ...] }` (each child open, own number, allocated lines/discounts, `version` 0). Original order becomes `voided` with `void_reason` `"split into <numbers>"` and NO restock. `ways` outside 2–10 → 400 `validation_failed`; captured payments → 422 `order_has_payments`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/Orders/SplitOrderTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id, 'table_ref' => 'T3']);
    $this->headers = fn (int $version) => staffHeaders($this->register, $this->cashier)
        + ['If-Match' => (string) $version, 'Idempotency-Key' => (string) Str::uuid()];
});

function splitAdd(object $t, int $priceCents, string $qty = '1', bool $tracked = false): void
{
    $variant = $tracked
        ? tap(ProductVariant::factory()->create(['price_cents' => $priceCents, 'tax_rate_id' => null, 'track_inventory' => true]),
            fn ($v) => app(App\Domain\Stock\StockLedger::class)->receive($v->id, $t->location->id, App\Domain\Money\Quantity::fromString('10'), null, 'seed'))
        : ProductVariant::factory()->untracked()->create(['price_cents' => $priceCents, 'tax_rate_id' => null]);
    $order = Order::findOrFail($t->order->id);
    app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $order->id, registerId: $t->register->id, variantId: $variant->id,
        qty: $qty, expectedVersion: $order->version, actorId: $t->cashier->id,
    ));
}

it('splits three ways with every column summing exactly', function (): void {
    splitAdd($this, 1000);          // does not divide by 3
    splitAdd($this, 333, '1');      // neither does this
    $original = Order::findOrFail($this->order->id);

    $response = $this->postJson("/api/v1/orders/{$this->order->id}/split", ['ways' => 3], ($this->headers)($original->version));
    $response->assertCreated();
    $children = $response->json('data.orders');

    expect($children)->toHaveCount(3);
    expect(array_sum(array_column($children, 'total_cents')))->toBe($original->total_cents)
        ->and(array_sum(array_column($children, 'subtotal_cents')))->toBe($original->subtotal_cents)
        ->and(array_sum(array_column($children, 'tax_cents')))->toBe($original->tax_cents);

    // each child got a share of each line, qty milli-exact: 1.000 → 0.334 + 0.333 + 0.333
    $qtys = array_map(fn ($c) => $c['lines'][0]['qty'], $children);
    expect($qtys)->toEqual(['0.334', '0.333', '0.333']);

    $original->refresh();
    expect($original->status)->toBe(OrderStatus::Voided)
        ->and($original->void_reason)->toContain('split into');
    $this->assertDatabaseHas('audit_log', ['action' => 'order.split', 'entity_id' => $original->id]);
});

it('does not touch stock — the children inherit the claim', function (): void {
    splitAdd($this, 500, '2', tracked: true);
    $before = StockLevel::where('location_id', $this->location->id)->value('qty');

    $order = Order::findOrFail($this->order->id);
    $this->postJson("/api/v1/orders/{$this->order->id}/split", ['ways' => 2], ($this->headers)($order->version))->assertCreated();

    expect(StockLevel::where('location_id', $this->location->id)->value('qty'))->toBe($before);
});

it('carries table_ref, shift and opener onto every child', function (): void {
    splitAdd($this, 500);
    $order = Order::findOrFail($this->order->id);
    $children = $this->postJson("/api/v1/orders/{$this->order->id}/split", ['ways' => 2], ($this->headers)($order->version))
        ->json('data.orders');
    foreach ($children as $child) {
        expect($child['table_ref'])->toBe('T3')->and($child['status'])->toBe('open');
    }
    expect(Order::findOrFail($children[0]['id'])->shift_id)->toBe($order->shift_id);
});

it('allocates order-level discounts exactly across children', function (): void {
    splitAdd($this, 999);
    $discount = App\Models\Discount::factory()->fixed(100)->create();
    $order = Order::findOrFail($this->order->id);
    app(App\Actions\Orders\ApplyDiscount::class)->execute(new App\Actions\Orders\ApplyDiscountInput(
        orderId: $order->id, registerId: $this->register->id, discountId: $discount->id,
        orderLineId: null, reason: 'test', expectedVersion: $order->version,
        actorId: staffWithRole($this->location, Roles::SUPERVISOR)->id,
    ));
    $order->refresh();

    $children = $this->postJson("/api/v1/orders/{$this->order->id}/split", ['ways' => 3], ($this->headers)($order->version))
        ->json('data.orders');
    expect(array_sum(array_column($children, 'discount_cents')))->toBe(100)
        ->and(array_sum(array_column($children, 'total_cents')))->toBe($order->total_cents);
});

it('refuses once money has been taken, and out-of-range ways', function (): void {
    splitAdd($this, 500);
    $order = Order::findOrFail($this->order->id);
    $order->forceFill(['paid_cents' => 100])->save();
    $this->postJson("/api/v1/orders/{$this->order->id}/split", ['ways' => 2], ($this->headers)($order->version))
        ->assertStatus(422)->assertJsonPath('error.code', 'order_has_payments');

    $order->forceFill(['paid_cents' => 0])->save();
    $this->postJson("/api/v1/orders/{$this->order->id}/split", ['ways' => 1], ($this->headers)($order->version))
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
    $this->postJson("/api/v1/orders/{$this->order->id}/split", ['ways' => 11], ($this->headers)($order->version))
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('exactness on hostile numbers: odd totals, fractional qty', function (): void {
    // 1089 does not divide by 2; 77×3=231 doesn't either; qty 3.000/2 = 1.500 each
    splitAdd($this, 1089);
    splitAdd($this, 77, '3');
    $original = Order::findOrFail($this->order->id);
    $originalLineTotals = $original->lines()->orderBy('position')->pluck('line_total_cents')->all();

    $children = $this->postJson("/api/v1/orders/{$this->order->id}/split", ['ways' => 2], ($this->headers)($original->version))
        ->assertCreated()->json('data.orders');

    expect(array_sum(array_column($children, 'total_cents')))->toBe($original->total_cents);
    foreach ([0, 1] as $lineIx) {
        $lineTotals = array_map(fn ($c) => $c['lines'][$lineIx]['line_total_cents'], $children);
        expect(array_sum($lineTotals))->toBe($originalLineTotals[$lineIx]);
    }
    // ponytail: one hostile case here; Money::allocate's own M1 property suite already
    // sweeps parts 2..10 — repeating it per-ways would test Money, not SplitOrder.
});
```

- [ ] **Step 2: Run to verify failure**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/SplitOrderTest.php`
Expected: FAIL — 404.

- [ ] **Step 3: Input, Request, Resource, route**

Input DTO `{ orderId, registerId, int ways, expectedVersion, actorId }`. Request: authorize `Permissions::ORDER_OPEN`; rules `['ways' => ['required', 'integer', 'min:2', 'max:10'], 'if_match' => [...]]` (out-of-range is malformed input → 400, per the global constraint). Resource:

```php
<?php
// backend/app/Http/Resources/SplitOrderResource.php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property list<\App\Models\Order> $resource */
final class SplitOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['orders' => OrderResource::collection(collect($this->resource))];
    }
}
```

Route (with idempotency — a lost response and a re-tap must not split twice):

```php
Route::post('/orders/{order}/split', SplitOrderController::class)
    ->middleware('idempotent')
    ->name('orders.split');
```

Controller returns `(new SplitOrderResource($action->execute($request->toInput())))->response()->setStatusCode(201)`.

- [ ] **Step 4: The action**

```php
<?php
// backend/app/Actions/Orders/SplitOrder.php
declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Money;
use App\Domain\Orders\OpenOrderLock;
use App\Domain\Orders\OrderNumbers;
use App\Exceptions\Domain\OrderHasPayments;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * Split evenly into N checks — each child gets 1/N of every line as a fractional
 * quantity with allocator-exact money (no column recomputed per child: recomputing
 * 1/N of a tax would mint pennies; Money::allocate is the only splitter).
 *
 * Stock is untouched — it left the ledger when the original lines were added and the
 * children inherit the claim. Which is why the original is closed out as voided
 * WITHOUT restock, written here directly rather than via VoidOrder (which restocks
 * by design). Voided orders are excluded from reporting sums, so nothing double-counts;
 * money truth stays in the payments/refunds ledgers as always.
 */
final class SplitOrder
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly OrderNumbers $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<Order> */
    public function execute(SplitOrderInput $in): array
    {
        return DB::transaction(function () use ($in): array {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            if ($order->paid_cents > 0) {
                throw new OrderHasPayments($order->id, $order->paid_cents);
            }

            $location = Location::findOrFail($order->location_id);
            $lines = $order->lines()->whereNull('voided_at')->with('modifiers')->orderBy('position')->get();
            $discountRows = $order->discounts()->get();   // order- and line-level rows

            $children = [];
            for ($i = 0; $i < $in->ways; $i++) {
                $children[] = Order::create([
                    'number' => $this->numbers->next($location, $order->business_date instanceof \Carbon\CarbonInterface ? $order->business_date->toDateString() : (string) $order->business_date),
                    'location_id' => $order->location_id,
                    'register_id' => $order->register_id,
                    'shift_id' => $order->shift_id,
                    'business_date' => $order->business_date,
                    'opened_by' => $order->opened_by,
                    'customer_id' => $order->customer_id,
                    'table_ref' => $order->table_ref,
                    'status' => 'open',
                    'prices_include_tax' => $order->prices_include_tax,
                    'subtotal_cents' => 0, 'discount_cents' => 0, 'tax_cents' => 0,
                    'total_cents' => 0, 'paid_cents' => 0, 'version' => 0,
                    'opened_at' => now(),
                ]);
            }

            foreach ($lines as $ix => $line) {
                $qtyParts = $this->allocateMilli(\App\Domain\Money\Quantity::fromString($line->qty)->milli, $in->ways);
                $totalParts = Money::fromCents($line->line_total_cents)->allocate($in->ways);
                $taxParts = Money::fromCents($line->tax_cents)->allocate($in->ways);
                $modParts = Money::fromCents($line->modifiers_total_cents)->allocate($in->ways);
                $discParts = Money::fromCents($line->discount_cents)->allocate($in->ways);

                foreach ($children as $c => $child) {
                    $childLine = $child->lines()->create([
                        'variant_id' => $line->variant_id,
                        'name_snapshot' => $line->name_snapshot,
                        'sku_snapshot' => $line->sku_snapshot,
                        'unit_price_cents' => $line->unit_price_cents,
                        'tax_rate_micros' => $line->tax_rate_micros,
                        'qty' => (string) \App\Domain\Money\Quantity::fromMilli($qtyParts[$c]),
                        'modifiers_total_cents' => $modParts[$c]->cents,
                        'discount_cents' => $discParts[$c]->cents,
                        'tax_cents' => $taxParts[$c]->cents,
                        'line_total_cents' => $totalParts[$c]->cents,
                        'prep_state' => $line->prep_state,
                        'position' => $ix,
                        'created_at' => now(),
                    ]);
                    foreach ($line->modifiers as $mod) {
                        $childLine->modifiers()->create([
                            'modifier_id' => $mod->modifier_id,
                            'name_snapshot' => $mod->name_snapshot,
                            'price_delta_cents' => $mod->price_delta_cents,
                        ]);
                    }
                    // line-level discount rows follow their line, allocated
                    foreach ($discountRows->where('order_line_id', $line->id) as $row) {
                        $rowParts = Money::fromCents($row->amount_cents)->allocate($in->ways);
                        if ($rowParts[$c]->isPositive()) {
                            $child->discounts()->create([
                                'order_line_id' => $childLine->id,
                                'discount_id' => $row->discount_id,
                                'name_snapshot' => $row->name_snapshot,
                                'amount_cents' => $rowParts[$c]->cents,
                                'applied_by' => $row->applied_by,
                                'reason' => $row->reason,
                            ]);
                        }
                    }
                }
            }

            foreach ($discountRows->whereNull('order_line_id') as $row) {
                $rowParts = Money::fromCents($row->amount_cents)->allocate($in->ways);
                foreach ($children as $c => $child) {
                    if ($rowParts[$c]->isPositive()) {
                        $child->discounts()->create([
                            'order_line_id' => null,
                            'discount_id' => $row->discount_id,
                            'name_snapshot' => $row->name_snapshot,
                            'amount_cents' => $rowParts[$c]->cents,
                            'applied_by' => $row->applied_by,
                            'reason' => $row->reason,
                        ]);
                    }
                }
            }

            // Child totals are SUMS of allocated parts — never recomputed.
            foreach ($children as $child) {
                $subtotal = (int) $child->lines()->sum('line_total_cents');
                $tax = (int) $child->lines()->sum('tax_cents');
                $discount = (int) $child->discounts()->sum('amount_cents');
                $child->forceFill([
                    'subtotal_cents' => $subtotal,
                    'discount_cents' => $discount,
                    'tax_cents' => $tax,
                    'total_cents' => $child->prices_include_tax ? $subtotal : $subtotal + $tax,
                ])->save();
            }

            $numbers = implode(', ', array_map(fn (Order $c) => $c->number, $children));
            $order->forceFill([
                'status' => OrderStatus::Voided,
                'voided_at' => now(),
                'void_reason' => "split into {$numbers}",
                'version' => $order->version + 1,
            ])->save();

            $childIds = array_map(fn (Order $c) => $c->id, $children);
            $this->audit->record('order.split', $order, $in->actorId, [
                'ways' => $in->ways, 'children' => $childIds,
            ], registerId: $in->registerId);
            foreach ($children as $child) {
                $this->audit->record('order.split.child', $child, $in->actorId, [
                    'parent' => $order->id,
                ], registerId: $in->registerId);
            }

            return array_map(fn (Order $c) => $c->fresh(['lines', 'discounts']), $children);
        });
    }

    /** 1000 milli / 3 → [334, 333, 333]; earliest absorbs, same convention as Money::allocate. @return list<int> */
    private function allocateMilli(int $milli, int $parts): array
    {
        $base = intdiv($milli, $parts);
        $remainder = $milli - $base * $parts;

        return array_map(fn (int $i) => $base + ($i < $remainder ? 1 : 0), range(0, $parts - 1));
    }
}
```

> `line_total_cents` on a child ≠ `unit_price × qty` after allocation — the allocated
> column is authoritative, exactly like a discounted line. `OrderTotals::recalculate`
> must NEVER run on a child while it has no mutations; the first later mutation
> (add a round to one check) recalculates that child normally and the invariant
> "children sum to the original" ends there by design — the checks are now
> independent orders.

- [ ] **Step 5: Run, then full suite; Commit**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Orders/SplitOrderTest.php && DB_PORT=5433 ./vendor/bin/pest`
Expected: PASS.

```bash
git add -A backend && git commit -m "M5: split evenly into N checks — allocator-exact, stock untouched, original voided without restock"
```

---

### Task 9: ApproveVariance + cross-order idempotency collision tests

**Files:**
- Create: `backend/app/Actions/Shifts/ApproveVariance.php`, `ApproveVarianceInput.php`
- Create: `backend/app/Http/Requests/Shifts/ApproveVarianceRequest.php`
- Create: `backend/app/Http/Controllers/Shifts/ApproveVarianceController.php`
- Create: `backend/app/Exceptions/Domain/VarianceAlreadyApproved.php`, `VarianceApprovalNotRequired.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Shifts/ApproveVarianceTest.php`, `backend/tests/Feature/Http/IdempotencyScopeTest.php` (add cross-order cases)

**Interfaces:**
- Consumes: `config('pos.shifts.variance_approval_threshold_cents')` (= 500), Task 1's columns.
- Produces: `POST /api/v1/shifts/{shift}/approve-variance` `{}`, permission `SHIFT_APPROVE_VARIANCE` → `{ shift }` (ShiftResource with `variance_approved_by/at` set). 422 `variance_approval_not_required` (open shift, or |variance| ≤ threshold), 422 `variance_already_approved`. Route name `shifts.approve-variance`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Feature/Shifts/ApproveVarianceTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    // threshold is 500 (config/pos.php); 600 short requires approval
    $this->shift = Shift::factory()->create([
        'register_id' => $this->register->id, 'opened_by' => $this->cashier->id,
        'closed_at' => now(), 'closed_by' => $this->cashier->id,
        'counted_cash_cents' => 0, 'expected_cash_cents' => 600, 'variance_cents' => -600,
    ]);
});

it('records the approval once, supervisor only', function (): void {
    $this->postJson("/api/v1/shifts/{$this->shift->id}/approve-variance", [],
        staffHeaders($this->register, $this->cashier))->assertStatus(403);

    $this->postJson("/api/v1/shifts/{$this->shift->id}/approve-variance", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertOk()
        ->assertJsonPath('data.shift.variance_approved_by', $this->supervisor->id);

    $this->assertDatabaseHas('audit_log', ['action' => 'shift.approve_variance', 'entity_id' => $this->shift->id]);

    $this->postJson("/api/v1/shifts/{$this->shift->id}/approve-variance", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(422)->assertJsonPath('error.code', 'variance_already_approved');
});

it('refuses when not required: under threshold, or shift still open', function (): void {
    $this->shift->forceFill(['variance_cents' => -500])->save();   // exactly at threshold = not over
    $this->postJson("/api/v1/shifts/{$this->shift->id}/approve-variance", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(422)->assertJsonPath('error.code', 'variance_approval_not_required');

    $open = Shift::factory()->create(['register_id' => registerAt($this->location)->id, 'opened_by' => $this->cashier->id]);
    $this->postJson("/api/v1/shifts/{$open->id}/approve-variance", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(422)->assertJsonPath('error.code', 'variance_approval_not_required');
});
```

Cross-order collision additions (M4 triage) — in the existing idempotency test file (find it under `tests/Feature/Http/`; if none matches, create `IdempotencyScopeTest.php`):

```php
it('the same key text on two different orders does not collide', function (): void {
    // fixture idiom from this file's siblings: one register, one cashier, two open orders
    // with one 500¢ untracked line each (AddLineToOrder), then:
    $key = (string) Illuminate\Support\Str::uuid();
    foreach ([$this->orderA, $this->orderB] as $order) {
        $this->postJson("/api/v1/orders/{$order->id}/payments",
            ['driver' => 'cash', 'amount_cents' => 500, 'tendered_cents' => 500],
            staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $order->version, 'Idempotency-Key' => $key],
        )->assertCreated();   // different path → different hash → both execute
    }
    expect(App\Models\Payment::count())->toBe(2);
});

it('replaying one of them still replays, not re-executes', function (): void {
    // take a payment with key K on order A twice: second response toEqual first, Payment::count() stays 1
});
```

(Write the second one fully with the same fixture — assertion: replay body `toEqual` original, exactly one Payment row.)

- [ ] **Step 2: Run to verify failure**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Shifts/ApproveVarianceTest.php`
Expected: FAIL — 404.

- [ ] **Step 3: Implement**

Exceptions per the `OrderNotZero` mold: `VarianceAlreadyApproved` (422, details `shift_id`, `approved_by`, `approved_at`), `VarianceApprovalNotRequired` (422, details `shift_id`, `reason` — `'shift_open'` or `'under_threshold'`). Input `{ shiftId, registerId, actorId }`. Request authorizes `Permissions::SHIFT_APPROVE_VARIANCE`, rules `[]`.

```php
<?php
// backend/app/Actions/Shifts/ApproveVariance.php
declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\VarianceAlreadyApproved;
use App\Exceptions\Domain\VarianceApprovalNotRequired;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * A supervisor signs off an over-threshold drawer variance. The shift is already
 * closed — approval is an audit event, never a gate on closing (docs/03-api.md:
 * blocking the close is how you end up with terminals unplugged mid-count).
 */
final class ApproveVariance
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ApproveVarianceInput $in): Shift
    {
        return DB::transaction(function () use ($in): Shift {
            $locationId = Register::findOrFail($in->registerId)->location_id;

            $shift = Shift::whereKey($in->shiftId)
                ->whereHas('register', fn ($q) => $q->where('location_id', $locationId))
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->closed_at === null) {
                throw new VarianceApprovalNotRequired($shift->id, 'shift_open');
            }
            $threshold = (int) config('pos.shifts.variance_approval_threshold_cents');
            if (abs((int) $shift->variance_cents) <= $threshold) {
                throw new VarianceApprovalNotRequired($shift->id, 'under_threshold');
            }
            if ($shift->variance_approved_at !== null) {
                throw new VarianceAlreadyApproved($shift->id, $shift->variance_approved_by, $shift->variance_approved_at->toIso8601String());
            }

            $shift->forceFill([
                'variance_approved_by' => $in->actorId,
                'variance_approved_at' => now(),
            ])->save();

            $this->audit->record('shift.approve_variance', $shift, $in->actorId, [
                'variance_cents' => $shift->variance_cents,
            ], registerId: $in->registerId);

            return $shift->refresh();
        });
    }
}
```

Controller returns `{ shift: ShiftResource }` (mirror `CloseShiftController`'s wrapping idiom, 200). Route:

```php
Route::post('/shifts/{shift}/approve-variance', ApproveVarianceController::class)
    ->name('shifts.approve-variance');
```

- [ ] **Step 4: Run, then full suite; Commit**

Run: `DB_PORT=5433 ./vendor/bin/pest tests/Feature/Shifts/ApproveVarianceTest.php tests/Feature/Http && DB_PORT=5433 ./vendor/bin/pest`
Expected: PASS.

```bash
git add -A backend && git commit -m "M5: approve-variance + cross-order idempotency collision proof"
```

---

### Task 10: Frontend API client — every M5 endpoint, typed

**Files:**
- Modify: `frontend/web/src/lib/api.ts`
- Test: `frontend/web/src/lib/api.test.ts` (extend — this file is owner-authored; ADD tests, do not restructure existing ones)

**Interfaces (produces — later tasks consume these exact names):**

```ts
export type RegisterInfo = { id: string; name: string; mode: 'retail' | 'food' }
// StaffSession gains: register: RegisterInfo
// tokens gains: setRegisterInfo(r: RegisterInfo): void; registerInfo(): RegisterInfo | null  (localStorage key 'pos.register_info'; staffLogin stores it)
// OrderLine gains: prep_state: 'pending' | 'in_progress' | 'ready' | null
//                  modifiers?: Array<{ name: string; price_delta_cents: number }>
// Order gains: opened_at?: string; opened_by_name?: string; due_cents: number
// Catalog becomes fully typed:
export type CatalogCategory = { id: string; name: string; position: number }
export type CatalogProduct = { id: string; category_id: string | null; name: string; modifier_group_ids: string[] }
export type CatalogVariant = { id: string; product_id: string; name: string; sku: string; barcode: string | null; price_cents: number; track_inventory: boolean }
export type ModifierGroup = { id: string; name: string; min_select: number; max_select: number | null; position: number }
export type Modifier = { id: string; group_id: string; name: string; price_delta_cents: number; position: number }
// api gains:
//   openOrder(opts?: { tableRef?: string; idempotencyKey?: string }): Promise<Order>       // body { table_ref } when set — REPLACES the old (idempotencyKey?) signature; update its callers (SaleScreen) in this task
//   addLine(order, variantId, qty = '1', idempotencyKey?, modifierIds?: string[]): Promise<Order>  // body gains modifiers when non-empty
//   updateLineQty(order: Order, lineId: string, qty: string): Promise<Order>                // PATCH lines/{id}, If-Match
//   setLinePrep(orderId: string, lineId: string, state: 'pending'|'in_progress'|'ready'): Promise<Order>
//   setTableRef(order: Order, tableRef: string | null): Promise<Order>                      // PATCH /orders/{id}, If-Match
//   transferOrder(order: Order, registerId: string): Promise<Order>                         // POST transfer, If-Match
//   splitOrder(order: Order, ways: number, idempotencyKey: string): Promise<Order[]>        // POST split → data.orders
//   approveVariance(shiftId: string): Promise<Shift>                                        // POST approve-variance → data.shift
//   openOrders(): Promise<Order[]>                                                          // findOrders({ status: 'open' }) alias for the floor view
```

- [ ] **Step 1: Write the failing tests** — extend `api.test.ts` in its existing style (it stubs `fetch`; copy the file's own harness idiom) with, at minimum: `splitOrder` unwraps `data.orders` and sends `If-Match` + `Idempotency-Key`; `addLine` includes `modifiers` in the body only when provided; `openOrder` sends `{ table_ref: 'T1' }` when `tableRef` passed and `{}` otherwise; `transferOrder` posts the target register id with If-Match; `tokens.registerInfo()` round-trips through localStorage.

- [ ] **Step 2: Run to verify failure**

Run: `cd frontend/web && npm test`
Expected: FAIL — methods don't exist.

- [ ] **Step 3: Implement** exactly the shapes above. `staffLogin` also stores the register:

```ts
staffLogin: async (pin: string): Promise<StaffSession> => {
  const session = await post<StaffSession>('/staff/login', { pin })
  tokens.setStaff(session.staff_token)
  tokens.setStaffUser(session.user)
  tokens.setRegisterInfo(session.register)
  return session
},
```

New methods follow the file's existing patterns exactly (If-Match from `order.version`, `.then((r) => r.order)` unwrapping — split unwraps `r.orders`).

- [ ] **Step 4: Run tests + typecheck**

Run: `cd frontend/web && npm test && npm run typecheck`
Expected: PASS. (SaleScreen's `openOrder(key)` callers updated to `openOrder({ idempotencyKey: key })` — typecheck enforces this.)

- [ ] **Step 5: Commit**

```bash
git add frontend/web/src/lib && git commit -m "M5: api client — modifiers, qty, prep, table_ref, transfer, split, approve-variance"
```

---

### Task 11: Menu grid + modifier sheet (food mode)

**Files:**
- Create: `frontend/web/src/register/MenuGrid.tsx`, `frontend/web/src/register/ModifierSheet.tsx`
- Modify: `frontend/web/src/register/SaleScreen.tsx` (render grid when `tokens.registerInfo()?.mode === 'food'`; keep the scan form visible in both modes)
- Modify: `frontend/web/src/index.css` (grid/sheet styles per DESIGN.md — plates, chips, 44px targets, warm color on the ADD action only)
- Test: `frontend/web/src/register/ModifierSheet.test.tsx`

**Interfaces:**
- Consumes: `api.catalog()` (`Catalog` with the Task 10 types), `api.addLine(order, variantId, qty, key, modifierIds)`.
- Produces: `<MenuGrid onPick={(variant: CatalogVariant, product: CatalogProduct) => void} />` — category rail + product tiles (variant tiles when a product has >1 variant), catalog via `useQuery({ queryKey: ['catalog'], staleTime: 5 * 60_000 })`. `<ModifierSheet product={...} groups={ModifierGroup[]} modifiers={Modifier[]} onConfirm={(modifierIds: string[]) => void} onCancel={() => void} />` — required groups (`min_select > 0`) sorted first; a modifier tap adds one selection (tap again on a multi-select group = repeat, "double bacon"); running price delta shown; CONFIRM disabled until every group's count is within `[min_select, max_select]`.

- [ ] **Step 1: Write the failing tests** (the sheet's enforcement logic is the testable core; grid rendering is thin):

```tsx
// frontend/web/src/register/ModifierSheet.test.tsx — follow the harness idiom of the
// existing frontend tests (they use @testing-library/react; check a sibling *.test.tsx)
import { render, screen, fireEvent } from '@testing-library/react'
import { ModifierSheet } from './ModifierSheet'

const groups = [
  { id: 'g-extras', name: 'Extras', min_select: 0, max_select: 2, position: 1 },
  { id: 'g-milk', name: 'Milk', min_select: 1, max_select: 1, position: 0 },
]
const modifiers = [
  { id: 'm-oat', group_id: 'g-milk', name: 'Oat', price_delta_cents: 60, position: 0 },
  { id: 'm-shot', group_id: 'g-extras', name: 'Extra shot', price_delta_cents: 75, position: 0 },
]

it('keeps CONFIRM disabled until required groups are satisfied, and orders required first', () => {
  const onConfirm = vi.fn()
  render(<ModifierSheet productName="Latte" groups={groups} modifiers={modifiers} onConfirm={onConfirm} onCancel={() => {}} />)

  const headings = screen.getAllByRole('heading').map((h) => h.textContent)
  expect(headings.indexOf('Milk')).toBeLessThan(headings.indexOf('Extras'))

  const confirm = screen.getByRole('button', { name: /add/i })
  expect(confirm).toBeDisabled()
  fireEvent.click(screen.getByRole('button', { name: /oat/i }))
  expect(confirm).toBeEnabled()
})

it('counts repeats toward max_select and passes repeated ids through', () => {
  const onConfirm = vi.fn()
  render(<ModifierSheet productName="Latte" groups={groups} modifiers={modifiers} onConfirm={onConfirm} onCancel={() => {}} />)
  fireEvent.click(screen.getByRole('button', { name: /oat/i }))
  fireEvent.click(screen.getByRole('button', { name: /extra shot/i }))
  fireEvent.click(screen.getByRole('button', { name: /extra shot/i }))
  fireEvent.click(screen.getByRole('button', { name: /extra shot/i }))   // 3rd exceeds max 2 → ignored
  fireEvent.click(screen.getByRole('button', { name: /add/i }))
  expect(onConfirm).toHaveBeenCalledWith(['m-oat', 'm-shot', 'm-shot'])
})
```

- [ ] **Step 2: Run to verify failure**

Run: `cd frontend/web && npm test`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement.** `ModifierSheet` is pure local state (`string[]` of selected ids in tap order); selection count per group derived on render; running delta `selected.reduce(...)` displayed via `formatMoney`. `MenuGrid` renders `catalog.categories` as a rail (first category active by default), tiles for that category's products; a product tap either confirms immediately (no attached groups) or opens `ModifierSheet`; multi-variant products show a variant chooser row in the sheet. In `SaleScreen`, food mode replaces the idle prompt area with `<MenuGrid onPick={...} />` and the pick path calls the same keyed `addLine` the scan path uses, passing `modifierIds`. Cart rows render `line.modifiers` indented under the line name, and (food mode) the prep chip from Task 12's CSS. Follow DESIGN.md: uppercase 11px labels, no rounded corners, warm color only on ADD.

- [ ] **Step 4: Verify**

Run: `cd frontend/web && npm test && npm run typecheck && npm run build`
Expected: PASS (one pre-existing benign lint warning about layout metadata is known).

- [ ] **Step 5: Commit**

```bash
git add frontend/web && git commit -m "M5: menu grid + modifier sheet — food mode ordering surface"
```

---

### Task 12: Floor view — tabs, new tab, transfer, prep chips

**Files:**
- Create: `frontend/web/src/register/FloorScreen.tsx`
- Modify: `frontend/web/src/register/Register.tsx` (new stage `'floor'`; food-mode registers land on `floor` after shift resolve; TABS/REGISTER toggle in the carbon bar)
- Modify: `frontend/web/src/register/SaleScreen.tsx` (accept `initialOrder?: Order` — resuming a tab from the floor seeds the sale screen; expose current order upward via `onOrderChange?: (o: Order | null) => void` so the floor button can warn about an in-progress cart; prep chips on cart lines in food mode)
- Modify: `frontend/web/src/index.css` (tab cards, prep chips)
- Test: `frontend/web/src/register/FloorScreen.test.tsx`

**Interfaces:**
- Consumes: `api.openOrders()`, `api.openOrder({ tableRef })`, `api.transferOrder`, `api.setLinePrep`, `tokens.registerInfo()`.
- Produces: `<FloorScreen registerId={string} canTransfer={boolean} onResume={(order: Order) => void} onNewTab={(order: Order) => void} onSessionExpired={() => void} />`. Polls with `useQuery({ queryKey: ['open-orders-floor'], queryFn: api.openOrders, refetchInterval: 10_000 })`. Tab cards show `table_ref ?? number`, `opened_by_name`, age from `opened_at`, `due_cents`; NEW TAB prompts for a table ref then `openOrder({ tableRef })` → `onNewTab`. TRANSFER on a card lists the location's other registers with open tabs… (target picker fetched from the same open-orders payload: distinct `register_id`s ≠ mine; label = that order's `opened_by_name`) and calls `api.transferOrder`; non-supervisors don't see TRANSFER (`canTransfer` from `can('order.transfer')`).

- [ ] **Step 1: Write the failing tests** — mock `api.openOrders` (module mock, same idiom as existing screen tests): renders one card per order with table ref and due; `onResume` fires with the picked order; TRANSFER hidden when `canTransfer` false.

- [ ] **Step 2: Run to verify failure** (`npm test`).

- [ ] **Step 3: Implement.** Floor is a plain list of `plate` cards (DESIGN.md), not a graphical room — that's deliberate (spec: Out of scope). The 10s `refetchInterval` runs only while mounted, which is the polling decision from the spec. Register.tsx: after `loading-shift` resolves, food-mode registers go to `{ name: 'floor', shift }`, retail unchanged (`selling`); carbon bar gains a TABS/REGISTER toggle (visible in food mode only) mirroring the Refunds toggle idiom; SaleScreen stays mounted-hidden on the floor stage exactly as it does for refunds (same strand-an-open-order reasoning, same `hidden` wrapper). Resuming a tab hands the order into SaleScreen via `initialOrder`; if a different order is already in progress on the sale screen, the floor resume button for other tabs is disabled with a "finish or park the current sale" hint (parking = it's a tab, it stays open server-side; the floor list shows it).

- [ ] **Step 4: Verify** (`npm test && npm run typecheck && npm run build`).

- [ ] **Step 5: Commit**

```bash
git add frontend/web && git commit -m "M5: floor view — tab cards, new tab, transfer picker, prep chips"
```

---

### Task 13: Split flow, multi-tender, blind count, approve-variance button

**Files:**
- Modify: `frontend/web/src/register/SaleScreen.tsx` (SPLIT ×N on the tender phase; child-check strip; tender screen already handles partial payments — surface `due_cents` from the server instead of client subtraction where shown)
- Modify: `frontend/web/src/register/ShiftScreens.tsx` (blind count: mask expected/variance until counted; approve-variance button on the result plate for supervisors)
- Modify: `frontend/web/src/lib/money.ts` — nothing new needed; the even-split suggestion uses the existing `allocate` (verify it exists; if the frontend lib lacks `allocate`, add it mirroring M1's backend semantics with a unit test: `allocate(cents(1000), 3) → [334, 333, 333]`)
- Test: extend `frontend/web/src/register/ShiftScreens.test.tsx` (or create) for the masking logic; money test for `allocate` if added
- Test: `frontend/web/src/register/SplitStrip.test.tsx` if the strip is extracted as a component (extract if SaleScreen grows past ~550 lines)

**Interfaces:**
- Consumes: `api.splitOrder(order, ways, key)`, `api.approveVariance(shiftId)`, `ShiftCloseResult.requires_approval`, `Shift.variance_approved_by`.
- Produces: tender phase gains `SPLIT ×2/×3/×4… ×10` (a small stepper + GO, one idempotency key per confirmed split); after a split the screen shows the child strip — each child's number + due, active child highlighted; paying a child through the normal tender flow advances to the next unpaid child; when all children are closed, a combined done plate offers per-child receipt printing. CloseShiftScreen: the Z-report still fetches at mount (session dies at close — unchanged M4 constraint) but the **expected cash and variance rows render masked (`•••••`) until the close result returns**; the count input is what the cashier sees first. On the result plate, when `requires_approval` and `can('shift.approve_variance')`, an APPROVE VARIANCE button calls `api.approveVariance` and swaps to an "approved by <name>" line on success; without the permission the M4 text ("needs supervisor approval") stands.

- [ ] **Step 1: Write the failing tests** — masking: render CloseShiftScreen (mock `api.zReport` resolving expected 12345), assert the expected-cash text is NOT in the document before submit and the masked placeholder is; approve button: render the result state with `requires_approval: true` and a `can` stub returning true, assert the button, click it (mock `api.approveVariance`), assert the approved line. Money `allocate` test if added.

- [ ] **Step 2: Run to verify failure** (`npm test`).

- [ ] **Step 3: Implement.** SaleScreen split path: `const children = await api.splitOrder(order, ways, key)` → store `{ children, activeIx: 0 }`; the active child becomes the working order for the tender phase (same code path — the split children are ordinary orders); closing the last child clears the strip and lands on the done plate listing every child receipt. The blind-count masking is presentation state only — `const [revealed, setRevealed] = useState(false)`, set true when the close result arrives; the Z data itself is fetched exactly as before. CloseShiftScreen needs the `can` function — pass it down from Register.tsx like SaleScreen already receives it.

- [ ] **Step 4: Verify** (`npm test && npm run typecheck && npm run build`).

- [ ] **Step 5: Commit**

```bash
git add frontend/web && git commit -m "M5: split flow, blind drawer count, variance approval from the close plate"
```

---

### Task 14: Docs, e2e lunch service, suite proof

**Files:**
- Modify: `docs/03-api.md` (move PATCH line, floor list, modifiers from "arrives in M5" to shipped; add transfer/split/prep/table_ref/approve-variance entries + the new error codes table rows), `docs/05-rbac.md` (order.transfer + shift.approve_variance now enforced; fired-line qty-decrease shares the void-a-sent-line gate), `docs/02-data-model.md` (registers.mode + shifts approval columns noted), `docs/06-roadmap.md` (M5 status: complete + what building it taught), `CLAUDE.md` (status: M5 complete; register modes note), `docs/00-overview.md` only if the thesis sentence needs its "screens, not tables" verdict recorded
- Create: `scripts/e2e-lunch-service.sh`
- Test: full suites, both sides

**Interfaces:** none — this task proves and records.

- [ ] **Step 1: e2e script.** Mirror `scripts/e2e-retail-day.sh`'s structure exactly: `set -euo pipefail`, `DEVICE="${POS_DEVICE_TOKEN:?...}"` (NEVER an embedded token — owner policy from M4), helper `req()` with curl + jq, asserts via `[ "$x" = "$y" ] || fail`. The story, against live servers (API :8000, seeded DB):
  1. PIN-login two staff on two registers (Till 2 food + Till 1), open both shifts (floats 10000).
  2. Tab A: open with `table_ref=T1`, add latte ×2 with oat + double shot (assert modifier math server-side), fire the course (`prep` → `in_progress`), add a second course later, PATCH one line's qty 2→3.
  3. Tab B: open `table_ref=T2`, add three lines.
  4. Transfer Tab A from Till 2's shift to Till 1's (supervisor PIN), assert its `register_id` moved.
  5. Split Tab B three ways; assert child totals sum to the original; pay two children cash, one card with a reference; assert each closed and receipts render with fractional qtys.
  6. Pay Tab A on Till 1 (it's that drawer's tab now); payout 300 from Till 2 to force a variance.
  7. Close both shifts; assert Till 1's expected cash includes Tab A's cash and Till 2's variance equals −300… then approve it (supervisor) and assert `variance_approved_by`.
  8. Print the summary table (orders, totals, variances) like the M4 script does.
- [ ] **Step 2: Run it.** `php artisan migrate:fresh --seed` (grab printed tokens), servers up, `POS_DEVICE_TOKEN=... POS_DEVICE_TOKEN_2=... bash scripts/e2e-lunch-service.sh`. Expected: every assert passes.
- [ ] **Step 3: Full suites.** `cd backend && DB_PORT=5433 ./vendor/bin/pest` (expect ~400+ tests green incl. `tests/Arch`), `cd frontend/web && npm test && npm run typecheck && npm run build`.
- [ ] **Step 4: Docs.** Each doc edit is surgical — the M4 wording for "arrives in M5" entries becomes present tense with the actual request/response shapes from the tasks above; roadmap M5 section gets a **Status: complete** block in the same voice as M3/M4's ("what building it changed"): record at minimum — the thesis held (three columns, zero order-model tables), split-evenly's allocator discipline, the no-restock void on split, prep state's no-version-bump rationale, the blind-count correction to the M4 close screen.
- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "M5: docs current + e2e lunch service green"
```

---

## Plan self-review (performed at write time)

- **Spec coverage:** tabs/floor (T5, T12), modifiers (T3, T11), split payments UI + due_cents (T5, T13), split-into-checks (T8, T13), transfer (T7, T12), mode switch (T1, T11, T12), prep (T6, T12), approve-variance (T9, T13), PATCH qty (T4), blind count (T13), triage items (T2 preamble, T5 due_cents + voided-line filter, T9 collision tests), docs/e2e (T14). Out-of-scope items appear in no task — correct.
- **Type consistency:** `OpenOrderLock::acquire(orderId, registerId, expectedVersion)` used identically in T3–T8; `api.splitOrder → Order[]` matches T13's consumption; `RegisterInfo.mode 'retail'|'food'` matches T1's CHECK constraint; `prep` route registered above the `{line}` PATCH (T6) — ordering note included.
- **Known judgment calls encoded:** split children are independent orders after creation (note in T8); prep has no version bump (T6 test asserts it); `ways` bounds are validation (400), payments-exist is domain (422) — matching the global 400/422 rule.

