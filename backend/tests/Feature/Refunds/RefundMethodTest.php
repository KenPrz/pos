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
