<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\ApplyDiscount;
use App\Actions\Orders\ApplyDiscountInput;
use App\Actions\Orders\RemoveDiscount;
use App\Actions\Orders\RemoveDiscountInput;
use App\Actions\Orders\VoidLine;
use App\Actions\Orders\VoidLineInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\DiscountScopeMismatch;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);

    // No tax_rate_id -> tax_rate_micros 0, so the numbers below are exact cents with no
    // rounding to reason about.
    $this->variant = ProductVariant::factory()->create(['price_cents' => 1000, 'track_inventory' => false]);
    $this->order = t7AddLine($this, $this->variant->id, '2');   // subtotal/total 2000c
    $this->line = $this->order->lines->first();
});

// Pest file-scoped functions collide across test files if two share a name — namespaced
// per task to keep this file free to move or be copied.
function t7AddLine(object $t, string $variantId, string $qty): Order
{
    return app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $t->order->id, variantId: $variantId, qty: $qty,
        expectedVersion: Order::findOrFail($t->order->id)->version, actorId: $t->cashier->id, registerId: $t->register->id,
    ))->order;
}

function t7ApplyDiscount(object $t, string $discountId, ?string $orderLineId = null, ?int $version = null, ?string $actorId = null): Order
{
    return app(ApplyDiscount::class)->execute(new ApplyDiscountInput(
        orderId: $t->order->id, registerId: $t->register->id, discountId: $discountId, orderLineId: $orderLineId,
        reason: 'Manager comp', expectedVersion: $version ?? Order::findOrFail($t->order->id)->version,
        actorId: $actorId ?? $t->supervisor->id,
    ));
}

it('applies a 10% order-level discount, reduces the total, bumps the version, and audits', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => '10% off']);

    $order = t7ApplyDiscount($this, $discount->id);

    expect($order->discount_cents)->toBe(200)          // 10% of 2000
        ->and($order->total_cents)->toBe(1800)
        ->and($order->version)->toBe($this->order->version + 1);
    $this->assertDatabaseHas('audit_log', ['action' => 'order.discount.apply', 'entity_type' => 'OrderDiscount']);
});

it('applies over HTTP and returns the whole order', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => '10% off']);
    $url = "/api/v1/orders/{$this->order->id}/discounts";
    $body = ['discount_id' => $discount->id, 'order_line_id' => null, 'reason' => 'Manager comp'];

    $this->postJson($url, $body, staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $this->order->version])
        ->assertOk()
        ->assertJsonPath('data.order.discount_cents', 200)
        ->assertJsonPath('data.order.total_cents', 1800)
        ->assertJsonPath('data.order.version', $this->order->version + 1);
});

it('applies a line-scoped discount to a valid line target', function (): void {
    $discount = Discount::factory()->percent(100_000)->line()->create(['name' => '10% off this item']);

    $order = t7ApplyDiscount($this, $discount->id, $this->line->id);

    expect($order->lines->first()->discount_cents)->toBe(200)
        ->and($order->discount_cents)->toBe(200)
        ->and($order->total_cents)->toBe(1800);
});

it('refuses a line discount aimed at a voided line', function (): void {
    $discount = Discount::factory()->percent(100_000)->line()->create(['name' => '10% off this item']);

    app(VoidLine::class)->execute(new VoidLineInput(
        orderId: $this->order->id, lineId: $this->line->id, registerId: $this->register->id,
        reason: 'Kitchen error', expectedVersion: Order::findOrFail($this->order->id)->version,
        actorId: $this->supervisor->id,
    ));

    $url = "/api/v1/orders/{$this->order->id}/discounts";
    $body = ['discount_id' => $discount->id, 'order_line_id' => $this->line->id, 'reason' => 'Manager comp'];

    $this->postJson($url, $body,
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) Order::findOrFail($this->order->id)->version])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'line_already_voided');
});

it('rejects an order-scoped discount sent with an order_line_id as a scope mismatch', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => '10% off']);   // scope: order

    expect(fn () => t7ApplyDiscount($this, $discount->id, $this->line->id))
        ->toThrow(DiscountScopeMismatch::class);
});

it('rejects an order-scoped discount sent with an order_line_id as a 422 over HTTP', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => '10% off']);
    $url = "/api/v1/orders/{$this->order->id}/discounts";
    $body = ['discount_id' => $discount->id, 'order_line_id' => $this->line->id, 'reason' => 'Manager comp'];

    $this->postJson($url, $body, staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $this->order->version])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'discount_scope_mismatch');
});

it('404s a line-scoped discount with no matching line on this order', function (): void {
    $discount = Discount::factory()->percent(100_000)->line()->create(['name' => '10% off this item']);

    expect(fn () => app(ApplyDiscount::class)->execute(new ApplyDiscountInput(
        orderId: $this->order->id, registerId: $this->register->id, discountId: $discount->id,
        orderLineId: (string) Str::uuid(), reason: 'x',
        expectedVersion: Order::findOrFail($this->order->id)->version, actorId: $this->supervisor->id,
    )))->toThrow(ModelNotFoundException::class);
});

it('is denied to a cashier and allowed to a supervisor over HTTP', function (): void {
    // requires_supervisor: true — the flag=false (cashier-safe) path is covered by
    // DiscountSupervisorFlagTest.php (Task 9).
    $discount = Discount::factory()->percent(100_000)->create(['name' => '10% off', 'requires_supervisor' => true]);
    $url = "/api/v1/orders/{$this->order->id}/discounts";
    $body = ['discount_id' => $discount->id, 'order_line_id' => null, 'reason' => 'Manager comp'];

    $this->postJson($url, $body, staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $this->order->version])
        ->assertStatus(403);
    $this->postJson($url, $body, staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $this->order->version])
        ->assertOk()
        ->assertJsonPath('data.order.discount_cents', 200);
});

it('removing a discount restores the total and audits', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => '10% off']);
    $order = t7ApplyDiscount($this, $discount->id);
    $orderDiscount = OrderDiscount::where('order_id', $order->id)->firstOrFail();

    $removed = app(RemoveDiscount::class)->execute(new RemoveDiscountInput(
        orderId: $order->id, registerId: $this->register->id, orderDiscountId: $orderDiscount->id,
        expectedVersion: $order->version, actorId: $this->supervisor->id,
    ));

    expect($removed->discount_cents)->toBe(0)
        ->and($removed->total_cents)->toBe(2000)
        ->and($removed->version)->toBe($order->version + 1);
    $this->assertDatabaseMissing('order_discounts', ['id' => $orderDiscount->id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'order.discount.remove', 'entity_id' => $orderDiscount->id]);
});

it('removes over HTTP', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => '10% off']);
    $order = t7ApplyDiscount($this, $discount->id);
    $orderDiscount = OrderDiscount::where('order_id', $order->id)->firstOrFail();

    $this->deleteJson("/api/v1/orders/{$order->id}/discounts/{$orderDiscount->id}", [],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $order->version])
        ->assertOk()
        ->assertJsonPath('data.order.discount_cents', 0)
        ->assertJsonPath('data.order.total_cents', 2000);
});

it('refuses to apply a discount to a closed order', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => '10% off']);
    $this->order->forceFill(['status' => 'closed'])->save();

    $url = "/api/v1/orders/{$this->order->id}/discounts";
    $body = ['discount_id' => $discount->id, 'order_line_id' => null, 'reason' => 'Manager comp'];

    $this->postJson($url, $body, staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $this->order->version])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'order_closed');
});

it('GET /catalog carries active discounts, shaped for the register', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => '10% off']);
    Discount::factory()->fixed(500)->create(['name' => '$5 off', 'is_active' => false]);   // hidden

    $device = ['Authorization' => 'Bearer '.$this->register->createToken("device:{$this->register->id}", ['device'])->plainTextToken];
    $response = $this->getJson('/api/v1/catalog', $device)->assertOk()->json('data');

    expect($response)->toHaveKey('discounts');
    $wire = collect($response['discounts'])->firstWhere('id', $discount->id);
    expect($wire)->not->toBeNull()
        ->and($wire)->toHaveKeys(['id', 'name', 'kind', 'percent_micros', 'amount_cents', 'scope', 'requires_supervisor'])
        ->and($wire['kind'])->toBe('percent')
        ->and($wire['percent_micros'])->toBe(100_000)
        ->and(collect($response['discounts'])->pluck('name'))->not->toContain('$5 off');
});
