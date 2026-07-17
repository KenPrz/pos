<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\ApplyDiscount;
use App\Actions\Orders\ApplyDiscountInput;
use App\Domain\Rbac\Roles;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Shift;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
});

function t15FullComp(object $t): Order
{
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 500, 'tax_rate_id' => null]);
    $order = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $t->order->id, variantId: $variant->id, qty: '1',
        expectedVersion: 0, actorId: $t->cashier->id, registerId: $t->register->id,
    ))->order;

    $comp = Discount::factory()->fixed(500)->create(['name' => '$5 off']);

    return app(ApplyDiscount::class)->execute(new ApplyDiscountInput(
        orderId: $order->id, registerId: $t->register->id, discountId: $comp->id,
        orderLineId: null, reason: 'full comp', expectedVersion: $order->version,
        actorId: $t->supervisor->id,
    ));
}

it('settles a fully comped order closed, without a payment row', function (): void {
    $order = t15FullComp($this);
    expect($order->total_cents)->toBe(0);

    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $order->version];
    $this->postJson("/api/v1/orders/{$order->id}/settle", [], $headers)
        ->assertOk()
        ->assertJsonPath('data.order.status', 'closed')
        ->assertJsonPath('data.order.paid_cents', 0);

    expect(Order::findOrFail($order->id)->status)->toBe(OrderStatus::Closed)
        ->and(Payment::where('order_id', $order->id)->count())->toBe(0);
    $this->assertDatabaseHas('audit_log', ['action' => 'order.settle_zero', 'entity_id' => $order->id]);
});

it('also settles an abandoned empty order, unblocking shift close', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];
    $this->postJson("/api/v1/orders/{$this->order->id}/settle", [], $headers)
        ->assertOk()
        ->assertJsonPath('data.order.status', 'closed');
});

it('refuses an order with money still on it', function (): void {
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 500, 'tax_rate_id' => null]);
    $order = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, variantId: $variant->id, qty: '1',
        expectedVersion: 0, actorId: $this->cashier->id, registerId: $this->register->id,
    ))->order;

    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $order->version];
    $this->postJson("/api/v1/orders/{$order->id}/settle", [], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'order_not_zero');
});

it('refunding a fully comped line is a clean 422, not a 500', function (): void {
    $order = t15FullComp($this);
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $order->version];
    $this->postJson("/api/v1/orders/{$order->id}/settle", [], $headers)->assertOk();

    $line = $order->lines()->first();
    $refundHeaders = staffHeaders($this->register, $this->supervisor)
        + ['Idempotency-Key' => (string) Str::uuid()];
    Shift::openFor($this->register->id) ?? Shift::factory()->create(['register_id' => $this->register->id]);

    $this->postJson('/api/v1/refunds', [
        'original_order_id' => $order->id,
        'driver' => 'cash',
        'reason' => 'comped anyway',
        'lines' => [['original_order_line_id' => $line->id, 'qty' => '1', 'restock' => false]],
    ], $refundHeaders)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'refund_amount_zero');
});
