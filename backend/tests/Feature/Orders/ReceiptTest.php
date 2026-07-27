<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\PaymentMethod;
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
        orderId: $this->order->id, registerId: $this->register->id, variantId: $this->variant->id, qty: '1',
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
