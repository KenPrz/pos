<?php
// backend/tests/Feature/Orders/SplitOrderTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
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
    // No App\Models\StockLevel Eloquent model exists in this codebase — every other
    // feature test reads stock_levels via DB::table (see UpdateLineQtyTest).
    $before = DB::table('stock_levels')->where('location_id', $this->location->id)->value('qty');

    $order = Order::findOrFail($this->order->id);
    $this->postJson("/api/v1/orders/{$this->order->id}/split", ['ways' => 2], ($this->headers)($order->version))->assertCreated();

    expect(DB::table('stock_levels')->where('location_id', $this->location->id)->value('qty'))->toBe($before);
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
