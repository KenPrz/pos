<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\Shift;

it('round-trips an order with integer money, string qty, and enum status', function (): void {
    $order = Order::factory()->create(['total_cents' => 1234]);
    $line = $order->lines()->create([
        'variant_id' => ProductVariant::factory()->create()->id,
        'name_snapshot' => 'T-Shirt — Blue / L',
        'sku_snapshot' => 'TSHIRT-BLUE-L',
        'unit_price_cents' => 1999,
        'tax_rate_micros' => 88750,
        'qty' => '0.500',
        'line_total_cents' => 1000,
        'created_at' => now(),
    ]);

    $fresh = Order::with('lines')->findOrFail($order->id);

    expect($fresh->status)->toBe(OrderStatus::Open)
        ->and($fresh->total_cents)->toBeInt()->toBe(1234)
        ->and($fresh->lines->first()->qty)->toBeString()->toBe('0.500')
        ->and($fresh->lines->first()->tax_rate_micros)->toBeInt();
});

it('creates a shift without eloquent timestamps', function (): void {
    $shift = Shift::factory()->create();

    expect($shift->opening_float_cents)->toBeInt()
        ->and($shift->closed_at)->toBeNull();
});
