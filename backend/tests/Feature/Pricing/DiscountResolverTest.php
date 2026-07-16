<?php

declare(strict_types=1);

use App\Domain\Pricing\OrderTotals;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;

/** Mirrors OrderTotalsTest's lineFor, but keeps the created line and sets position
 *  explicitly — the resolver's "earliest absorbs" penny rule depends on line order. */
function discountLine(Order $order, int $unitCents, int $rateMicros, string $qty = '1'): OrderLine
{
    return $order->lines()->create([
        'variant_id' => ProductVariant::factory()->create()->id,
        'name_snapshot' => 'x',
        'sku_snapshot' => 'x',
        'unit_price_cents' => $unitCents,
        'tax_rate_micros' => $rateMicros,
        'qty' => $qty,
        'line_total_cents' => 0,
        'position' => $order->lines()->count(),
        'created_at' => now(),
    ]);
}

function applyDiscount(Order $order, Discount $discount, ?string $lineId = null): OrderDiscount
{
    return OrderDiscount::create([
        'order_id' => $order->id,
        'order_line_id' => $lineId,
        'discount_id' => $discount->id,
        'name_snapshot' => $discount->name,
        'amount_cents' => 0,   // placeholder; DiscountResolver resolves and rewrites it
        'applied_by' => User::factory()->create()->id,
        'created_at' => now(),
    ]);
}

it('splits a 10% order discount across lines by base ratio', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    $line1 = discountLine($order, 700, 0);
    $line2 = discountLine($order, 300, 0);
    $discount = Discount::factory()->percent(100_000)->create();
    $row = applyDiscount($order, $discount);

    app(OrderTotals::class)->recalculate($order);

    expect($line1->fresh()->discount_cents)->toBe(70)
        ->and($line2->fresh()->discount_cents)->toBe(30)
        ->and($row->fresh()->amount_cents)->toBe(100)
        ->and($order->fresh()->discount_cents)->toBe(100)
        ->and($order->fresh()->total_cents)->toBe(900);
});

it('allocates the undivided penny to the earliest line (34/33/33)', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    $lines = [
        discountLine($order, 333, 0),
        discountLine($order, 333, 0),
        discountLine($order, 333, 0),
    ];
    $discount = Discount::factory()->percent(100_000)->create();
    $row = applyDiscount($order, $discount);

    app(OrderTotals::class)->recalculate($order);

    // 10% of 999 = 99.9 -> rounds to 100 (Money::fraction, half away from zero).
    expect($row->fresh()->amount_cents)->toBe(100)
        ->and($lines[0]->fresh()->discount_cents)->toBe(34)
        ->and($lines[1]->fresh()->discount_cents)->toBe(33)
        ->and($lines[2]->fresh()->discount_cents)->toBe(33)
        ->and($order->fresh()->total_cents)->toBe(999 - 100);
});

it('clamps a fixed discount to the order total, never going negative', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    discountLine($order, 700, 0);
    $discount = Discount::factory()->fixed(1000)->create();
    $row = applyDiscount($order, $discount);

    app(OrderTotals::class)->recalculate($order);

    expect($row->fresh()->amount_cents)->toBe(700)
        ->and($order->fresh()->discount_cents)->toBe(700)
        ->and($order->fresh()->total_cents)->toBe(0);
});

it('taxes the discounted base for a line-level discount', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    $line = discountLine($order, 1999, 88_750, '1');
    $discount = Discount::factory()->percent(500_000)->line()->create();
    applyDiscount($order, $discount, $line->id);

    app(OrderTotals::class)->recalculate($order);

    // amountFor(1999 @ 50%) = 999.5 -> 1000 (half away from zero). Net base 999.
    // tax = taxOnNet(999 @ 8.875%) = round(88.66125) = 89, not round(1999*0.08875)=177.
    expect($line->fresh()->discount_cents)->toBe(1000)
        ->and($line->fresh()->line_total_cents)->toBe(999)
        ->and($line->fresh()->tax_cents)->toBe(89);
});

it('rewrites a percent row upward when a line is added and totals are recalculated', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    discountLine($order, 700, 0);
    $discount = Discount::factory()->percent(100_000)->create();
    $row = applyDiscount($order, $discount);

    app(OrderTotals::class)->recalculate($order);
    expect($row->fresh()->amount_cents)->toBe(70);

    discountLine($order, 300, 0);
    app(OrderTotals::class)->recalculate($order);

    // Same row, rewritten in place — not a stale 70, and not a new row.
    expect(OrderDiscount::where('order_id', $order->id)->count())->toBe(1)
        ->and($row->fresh()->amount_cents)->toBe(100);
});

it('resolves a discount row on a voided line to zero', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    $line = discountLine($order, 1000, 0);
    $discount = Discount::factory()->fixed(500)->line()->create();
    $row = applyDiscount($order, $discount, $line->id);

    app(OrderTotals::class)->recalculate($order);
    expect($row->fresh()->amount_cents)->toBe(500);

    $line->forceFill(['voided_at' => now()])->save();
    app(OrderTotals::class)->recalculate($order);

    expect($row->fresh()->amount_cents)->toBe(0)
        ->and($order->fresh()->subtotal_cents)->toBe(0)
        ->and($order->fresh()->discount_cents)->toBe(0)
        ->and($order->fresh()->total_cents)->toBe(0);
});
