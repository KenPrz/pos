<?php

declare(strict_types=1);

use App\Domain\Pricing\OrderTotals;
use App\Models\Order;
use App\Models\ProductVariant;

function lineFor(Order $order, int $unitCents, int $rateMicros, string $qty): void
{
    $order->lines()->create([
        'variant_id' => ProductVariant::factory()->create()->id,
        'name_snapshot' => 'x',
        'sku_snapshot' => 'x',
        'unit_price_cents' => $unitCents,
        'tax_rate_micros' => $rateMicros,
        'qty' => $qty,
        'line_total_cents' => 0,
        'created_at' => now(),
    ]);
}

it('adds tax on top when prices exclude tax (US mode)', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    // 2 × $19.99 at NYC 8.875%: base 3998, tax = round(3998 × 0.08875) = 355
    lineFor($order, 1999, 88750, '2');

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->subtotal_cents)->toBe(3998)
        ->and($order->tax_cents)->toBe(355)
        ->and($order->total_cents)->toBe(4353)
        ->and($order->lines()->first()->line_total_cents)->toBe(3998)
        ->and($order->lines()->first()->tax_cents)->toBe(355);
});

it('extracts tax from the price when prices include tax (UK mode)', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => true]);
    // £3.50 latte at 20% VAT: tax = round(350 × 0.2/1.2) = 58; customer pays 350
    lineFor($order, 350, 200_000, '1');

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->subtotal_cents)->toBe(350)
        ->and($order->tax_cents)->toBe(58)
        ->and($order->total_cents)->toBe(350);
});

it('rounds per line then sums — never sums then rounds', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    // Three lines of 333 at 10%: per-line tax round(33.3)=33 ×3 = 99.
    // Sum-then-round would give round(999 × 0.1) = 100 — visibly wrong on paper.
    foreach (range(1, 3) as $i) {
        lineFor($order, 333, 100_000, '1');
    }

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->tax_cents)->toBe(99)->and($order->total_cents)->toBe(999 + 99);
});

it('handles fractional quantities through the one rounding primitive', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    // 0.500 kg × $24.00 = 1200; 20% tax = 240
    lineFor($order, 2400, 200_000, '0.500');

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->subtotal_cents)->toBe(1200)->and($order->tax_cents)->toBe(240);
});

it('ignores voided lines', function (): void {
    $order = Order::factory()->create(['prices_include_tax' => false]);
    lineFor($order, 1000, 0, '1');
    $order->lines()->first()->forceFill(['voided_at' => now()])->save();

    app(OrderTotals::class)->recalculate($order);
    $order->refresh();

    expect($order->subtotal_cents)->toBe(0)->and($order->total_cents)->toBe(0);
});
