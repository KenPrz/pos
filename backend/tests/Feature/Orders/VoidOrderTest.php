<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\VoidLine;
use App\Actions\Orders\VoidLineInput;
use App\Actions\Orders\VoidOrder;
use App\Actions\Orders\VoidOrderInput;
use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Domain\Money\Quantity;
use App\Domain\Rbac\Roles;
use App\Domain\Stock\StockLedger;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);

    $this->variantA = ProductVariant::factory()->create(['price_cents' => 1000, 'track_inventory' => true]);
    $this->variantB = ProductVariant::factory()->create(['price_cents' => 500, 'track_inventory' => true]);
    DB::transaction(fn () => app(StockLedger::class)->receive($this->variantA->id, $this->location->id, Quantity::fromString('10')));
    DB::transaction(fn () => app(StockLedger::class)->receive($this->variantB->id, $this->location->id, Quantity::fromString('10')));

    $this->order = t4AddLine($this, $this->variantA->id, '2');
    $this->order = t4AddLine($this, $this->variantB->id, '3');
});

function t4AddLine(object $t, string $variantId, string $qty): Order
{
    return app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $t->order->id, variantId: $variantId, qty: $qty,
        expectedVersion: $t->order->version ?? 0, actorId: $t->cashier->id, registerId: $t->register->id,
    ))->order;
}

function t4VoidOrder(object $t, ?string $reason = 'Walkout', ?int $version = null, ?string $actorId = null): Order
{
    return app(VoidOrder::class)->execute(new VoidOrderInput(
        orderId: $t->order->id, registerId: $t->register->id, reason: $reason ?? 'Walkout',
        expectedVersion: $version ?? $t->order->version, actorId: $actorId ?? $t->supervisor->id,
    ));
}

it('voids an open order, restocks every tracked line, and stores the reason', function (): void {
    $order = t4VoidOrder($this);

    expect($order->status->value)->toBe('voided')
        ->and($order->voided_at)->not->toBeNull()
        ->and($order->void_reason)->toBe('Walkout')
        ->and($order->version)->toBe($this->order->version + 1);

    expect(DB::table('stock_levels')->where('variant_id', $this->variantA->id)->value('qty'))->toBe('10.000');
    expect(DB::table('stock_levels')->where('variant_id', $this->variantB->id)->value('qty'))->toBe('10.000');
    $this->assertDatabaseHas('audit_log', ['action' => 'order.void', 'entity_id' => $order->id]);
});

it('refuses to void an order that has been paid, over HTTP', function (): void {
    app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $this->order->id, registerId: $this->register->id, driver: 'cash',
        amountCents: 500, tenderedCents: 500, reference: null,
        expectedVersion: $this->order->version, actorId: $this->cashier->id,
    ));

    $order = Order::findOrFail($this->order->id);

    $this->postJson("/api/v1/orders/{$this->order->id}/void", ['reason' => 'Walkout'],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $order->version])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'order_has_payments')
        ->assertJsonPath('error.details.order_id', $order->id)
        ->assertJsonPath('error.details.paid_cents', 500);
});

it('does not restock an already-voided line twice', function (): void {
    $line = $this->order->lines->firstWhere('variant_id', $this->variantA->id);

    $order = app(VoidLine::class)->execute(new VoidLineInput(
        orderId: $this->order->id, lineId: $line->id, registerId: $this->register->id,
        reason: 'mis-scan', expectedVersion: $this->order->version, actorId: $this->supervisor->id,
    ));

    // variantA is back to 10 already from the individual line void; voiding the whole
    // order must not post a second refund movement for the same line.
    t4VoidOrder($this, version: $order->version);

    expect(DB::table('stock_levels')->where('variant_id', $this->variantA->id)->value('qty'))->toBe('10.000');
    expect(DB::table('stock_movements')->where('ref_type', 'order_line')->where('ref_id', $line->id)->where('reason', 'refund')->count())->toBe(1);
});

it('is denied to a cashier and allowed to a supervisor over HTTP', function (): void {
    $url = "/api/v1/orders/{$this->order->id}/void";
    $body = ['reason' => 'Walkout'];

    $this->postJson($url, $body, staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $this->order->version])
        ->assertStatus(403);
    $this->postJson($url, $body, staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $this->order->version])
        ->assertOk()
        ->assertJsonPath('data.order.status', 'voided');
});

it('requires a reason', function (): void {
    $this->postJson("/api/v1/orders/{$this->order->id}/void", [],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $this->order->version])
        ->assertStatus(400);
});
