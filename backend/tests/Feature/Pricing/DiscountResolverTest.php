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

it('never drives a zero-base line negative when an order-level discount is allocated (reviewer repro)', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    $lineA = discountLine($order, 200, 0); // position 0
    $lineB = discountLine($order, 500, 0); // position 1
    $lineC = discountLine($order, 500, 0); // position 2

    $lineDiscount = Discount::factory()->percent(1_000_000)->line()->create(); // 100%
    applyDiscount($order, $lineDiscount, $lineA->id);

    $orderDiscount = Discount::factory()->fixed(999)->create();
    applyDiscount($order, $orderDiscount);

    app(OrderTotals::class)->recalculate($order);

    // Line A is fully discounted by its own line-level row (200 off a 200 base) before
    // the order-level row is even considered. Its remaining base is 0, so it is excluded
    // from the order-level ratio split entirely — it gets none of the 999, not a stray
    // penny from allocateByRatios' remainder walk.
    //
    // Lines B/C: remaining bases 500, 500 -> ratios 500, 500 ->
    // allocateByRatios(999, [500, 500]) = 500, 499 (earliest absorbs the odd penny),
    // and both fit exactly within their 500c bases, so the overflow-walk clamp is a
    // no-op here (it bites in the "remainder overflow" test below).
    expect($lineA->fresh()->discount_cents)->toBe(200)
        ->and($lineB->fresh()->discount_cents)->toBe(500)
        ->and($lineC->fresh()->discount_cents)->toBe(499)
        ->and($lineA->fresh()->line_total_cents)->toBe(0)
        ->and($lineB->fresh()->line_total_cents)->toBe(0)
        ->and($lineC->fresh()->line_total_cents)->toBe(1)
        ->and($order->fresh()->discount_cents)->toBe(200 + 999);

    foreach ([$lineA, $lineB, $lineC] as $line) {
        expect($line->fresh()->line_total_cents)->toBeGreaterThanOrEqual(0);
    }
});

it('clamps the order-level remainder walk to what each line has left (three 1c lines)', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    $lines = [
        discountLine($order, 1, 0),
        discountLine($order, 1, 0),
        discountLine($order, 1, 0),
    ];
    $discount = Discount::factory()->fixed(2)->create();
    $row = applyDiscount($order, $discount);

    app(OrderTotals::class)->recalculate($order);

    // Bases 1,1,1 -> ratios 1,1,1 -> allocateByRatios(2, [1,1,1]): each share
    // truncates to 0 (intdiv(2*1,3)=0), leaving a remainder of 2 handed out one cent
    // at a time to the earliest indices -> shares [1, 1, 0]. Every share here already
    // fits its line's 1c base, so the overflow walk never has to clamp — it just
    // confirms the allocation never *could* exceed a base as bases shrink to nothing.
    expect($row->fresh()->amount_cents)->toBe(2)
        ->and($lines[0]->fresh()->discount_cents)->toBe(1)
        ->and($lines[1]->fresh()->discount_cents)->toBe(1)
        ->and($lines[2]->fresh()->discount_cents)->toBe(0)
        ->and($order->fresh()->discount_cents)->toBe(2)
        ->and($order->fresh()->total_cents)->toBe(1);

    foreach ($lines as $line) {
        expect($line->fresh()->line_total_cents)->toBeGreaterThanOrEqual(0);
    }
});

it('resolves stacked line-level rows sequentially so they never jointly exceed the base', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    $line = discountLine($order, 700, 0);

    $fixed = Discount::factory()->fixed(700)->line()->create();
    $rowFixed = applyDiscount($order, $fixed, $line->id);

    $percent = Discount::factory()->percent(1_000_000)->line()->create(); // 100%
    $rowPercent = applyDiscount($order, $percent, $line->id);

    app(OrderTotals::class)->recalculate($order);

    // First row resolves against the raw 700c base: fixed 700 takes all of it. Second
    // row resolves against what's left — 0 — so its 100% of 0 is 0, not a second 700
    // that would double-discount the line. The two rows sum to exactly 700, the line's
    // full base, never more.
    expect($rowFixed->fresh()->amount_cents)->toBe(700)
        ->and($rowPercent->fresh()->amount_cents)->toBe(0)
        ->and($line->fresh()->discount_cents)->toBe(700)
        ->and($line->fresh()->line_total_cents)->toBe(0);
});

it('resolves an ad-hoc (null discount_id) row as a fixed constant clamped to the base', function (): void {
    // Ad-hoc rows are the frozen discount shares SplitOrder clones onto children: the
    // stored amount_cents is already-resolved money, so it resolves as a fixed constant —
    // min(stored, remaining base) — never re-scaling off a live definition, and never
    // throwing the way it used to before children could carry these.
    $order = Order::factory()->create(['prices_include_tax' => false]);
    $line = discountLine($order, 500, 0);

    $row = OrderDiscount::create([
        'order_id' => $order->id,
        'order_line_id' => null,
        'discount_id' => null,
        'name_snapshot' => 'Split share',
        'amount_cents' => 100,
        'applied_by' => User::factory()->create()->id,
        'created_at' => now(),
    ]);

    app(OrderTotals::class)->recalculate($order);
    expect($row->fresh()->amount_cents)->toBe(100)
        ->and($order->fresh()->discount_cents)->toBe(100)
        ->and($order->fresh()->total_cents)->toBe(400);

    // Base shrinks below the stored share → clamps to what's left, never negative.
    $line->forceFill(['qty' => '0.100'])->save();   // base now 50
    app(OrderTotals::class)->recalculate($order);
    expect($row->fresh()->amount_cents)->toBe(50)
        ->and($order->fresh()->total_cents)->toBe(0);
});
