<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\ReopenOrder;
use App\Actions\Orders\ReopenOrderInput;
use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\OrderClosed;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $this->variant = ProductVariant::factory()->create(['price_cents' => 1000, 'track_inventory' => false]);

    $this->order = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, variantId: $this->variant->id, qty: '1',
        expectedVersion: 0, actorId: $this->cashier->id, registerId: $this->register->id,
    ))->order;

    // Pay it in full so it auto-closes — the state Reopen operates on.
    $this->order = app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $this->order->id, registerId: $this->register->id, driver: 'cash',
        amountCents: 1000, tenderedCents: 1000, reference: null,
        expectedVersion: $this->order->version, actorId: $this->cashier->id,
    ))->order;

    expect($this->order->status)->toBe(OrderStatus::Closed);
});

function t4Reopen(object $t, ?string $reason = 'Another round', ?string $actorId = null): Order
{
    return app(ReopenOrder::class)->execute(new ReopenOrderInput(
        orderId: $t->order->id, registerId: $t->register->id, reason: $reason ?? 'Another round',
        actorId: $actorId ?? $t->supervisor->id,
    ));
}

it('reopens a paid-closed order without touching payments', function (): void {
    $order = t4Reopen($this);

    expect($order->status)->toBe(OrderStatus::Open)
        ->and($order->closed_at)->toBeNull()
        ->and($order->closed_by)->toBeNull()
        ->and($order->paid_cents)->toBe(1000)
        ->and($order->version)->toBe($this->order->version + 1);
    $this->assertDatabaseHas('audit_log', ['action' => 'order.reopen', 'entity_id' => $order->id]);
});

it('adding a line then paying the difference re-closes the reopened order', function (): void {
    $order = t4Reopen($this);

    $order = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $order->id, variantId: $this->variant->id, qty: '1',
        expectedVersion: $order->version, actorId: $this->cashier->id, registerId: $this->register->id,
    ))->order;

    expect($order->status)->toBe(OrderStatus::Open);
    $balance = $order->total_cents - $order->paid_cents;

    $payment = app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $order->id, registerId: $this->register->id, driver: 'cash',
        amountCents: $balance, tenderedCents: $balance, reference: null,
        expectedVersion: $order->version, actorId: $this->cashier->id,
    ));

    expect($payment->order->status)->toBe(OrderStatus::Closed)
        ->and($payment->order->paid_cents)->toBe($payment->order->total_cents);
});

it('refuses to reopen an order that is already open', function (): void {
    $order = t4Reopen($this);

    expect(fn () => app(ReopenOrder::class)->execute(new ReopenOrderInput(
        orderId: $order->id, registerId: $this->register->id, reason: 'again', actorId: $this->supervisor->id,
    )))->toThrow(OrderClosed::class);
});

it('is denied to a cashier and allowed to a supervisor over HTTP', function (): void {
    $url = "/api/v1/orders/{$this->order->id}/reopen";
    $body = ['reason' => 'Another round'];

    $this->postJson($url, $body, staffHeaders($this->register, $this->cashier))
        ->assertStatus(403);
    $this->postJson($url, $body, staffHeaders($this->register, $this->supervisor))
        ->assertOk()
        ->assertJsonPath('data.order.status', 'open');
});

it('requires a reason', function (): void {
    $this->postJson("/api/v1/orders/{$this->order->id}/reopen", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(400);
});
