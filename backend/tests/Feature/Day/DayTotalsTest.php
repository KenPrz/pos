<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\OpenOrder;
use App\Actions\Orders\OpenOrderInput;
use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Day\DayTotals;
use App\Domain\Rbac\Roles;
use App\Models\Location;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Shift;
use App\Models\TaxRate;

// Pest file-scoped functions collide across test files if two share a name — namespaced
// per task to keep this file free to move or be copied.
function dayTotalsRingUpCashSale(Location $location, TaxRate $taxRate): Order
{
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 1000, 'tax_rate_id' => $taxRate->id]);

    app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 20000, actorId: $cashier->id,
    ));

    $order = app(OpenOrder::class)->execute(new OpenOrderInput(
        registerId: $register->id, actorId: $cashier->id, tableRef: null, customerId: null,
    ));
    app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $order->id, registerId: $register->id, variantId: $variant->id, qty: '1',
        expectedVersion: $order->version, actorId: $cashier->id,
    ));
    $order = Order::findOrFail($order->id);
    app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $order->id, registerId: $register->id, driver: 'cash',
        amountCents: $order->total_cents, tenderedCents: $order->total_cents,
        reference: null, expectedVersion: $order->version, actorId: $cashier->id,
    ));

    $expectedCash = 20000 + Order::findOrFail($order->id)->total_cents;
    $shift = Shift::query()->where('register_id', $register->id)->whereNull('closed_at')->firstOrFail();
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id,
        countedCashCents: $expectedCash, note: null, actorId: $cashier->id,
    ));

    return Order::findOrFail($order->id);
}

// A single cash sale on a fresh drawer: gross == that payment, expected cash ==
// float + cash sale, and once the drawer is counted exactly, variance is zero.
// Tax-inclusive location (Asia/Manila, VAT-inclusive): the 20% tax is embedded in the
// shelf price, so total_cents == subtotal_cents (tax is extracted, not added).
it('snapshots one location-day from the ledgers, counted drawer and all', function (): void {
    $location = provisionedLocation(['code' => 'DT', 'timezone' => 'Asia/Manila', 'prices_include_tax' => true]);
    $taxRate = TaxRate::factory()->create();   // 20%, per TaxRateFactory

    $order = dayTotalsRingUpCashSale($location, $taxRate);
    $expectedCash = 20000 + $order->total_cents;

    expect($order->tax_cents)->toBeGreaterThan(0)
        ->and($order->total_cents)->toBe($order->subtotal_cents); // inclusive: tax is embedded, not added

    $today = now($location->timezone)->toDateString();
    $snap = app(DayTotals::class)->for($location->id, $today);

    expect($snap->grossSalesCents)->toBe($order->total_cents)
        ->and($snap->refundsCents)->toBe(0)
        ->and($snap->netSalesCents)->toBe($order->total_cents)
        ->and($snap->taxCents)->toBe($order->tax_cents)
        ->and($snap->expectedCashCents)->toBe($expectedCash)
        ->and($snap->countedCashCents)->toBe($expectedCash)
        ->and($snap->varianceCents)->toBe(0)
        ->and($snap->shiftCount)->toBe(1);
});

// Same story, tax-EXCLUSIVE location (US-style: shelf price is net, tax is added at the
// till) — proves DayTotals reads orders.tax_cents correctly regardless of tax mode.
it('snapshots one location-day for a tax-exclusive location — tax added on top, not embedded', function (): void {
    $location = provisionedLocation(['code' => 'EX', 'timezone' => 'America/New_York', 'prices_include_tax' => false]);
    $taxRate = TaxRate::factory()->create();   // 20%, per TaxRateFactory

    $order = dayTotalsRingUpCashSale($location, $taxRate);
    $expectedCash = 20000 + $order->total_cents;

    expect($order->tax_cents)->toBeGreaterThan(0)
        ->and($order->total_cents)->toBe($order->subtotal_cents + $order->tax_cents); // exclusive: tax added on top

    $today = now($location->timezone)->toDateString();
    $snap = app(DayTotals::class)->for($location->id, $today);

    expect($snap->grossSalesCents)->toBe($order->total_cents)
        ->and($snap->refundsCents)->toBe(0)
        ->and($snap->netSalesCents)->toBe($order->total_cents)
        ->and($snap->taxCents)->toBe($order->tax_cents)
        ->and($snap->expectedCashCents)->toBe($expectedCash)
        ->and($snap->countedCashCents)->toBe($expectedCash)
        ->and($snap->varianceCents)->toBe(0)
        ->and($snap->shiftCount)->toBe(1);
});
