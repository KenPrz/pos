<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\TaxRate;
use Database\Seeders\GrocerySeeder;
use Illuminate\Support\Facades\DB;

it('seeds the grocery catalog: Manila location, retail tills, 200 items, ledger stock', function (): void {
    $result = (new GrocerySeeder)->seed();
    $location = $result['location'];

    expect($location->code)->toBe('GRC');
    expect($location->timezone)->toBe('Asia/Manila');
    expect($location->prices_include_tax)->toBeTrue();

    expect(Register::query()->where('location_id', $location->id)->orderBy('name')->pluck('mode')->all())
        ->toBe(['retail', 'retail']);
    expect($result['tokens'])->toHaveCount(2);
    expect($result['tokens'][0][0])->toBe('GRC / Till 1');

    expect(ProductVariant::query()->count())->toBe(200);

    $anchor = ProductVariant::query()->where('sku', 'GRC-NESCAFE-100')->firstOrFail();
    expect($anchor->barcode)->toBe('4809990000016');
    expect($anchor->price_cents)->toBe(18500);
    expect($anchor->taxRate->name)->toBe('VAT 12%');
    expect(TaxRate::query()->where('name', 'VAT 12%')->firstOrFail()->rate_micros)->toBe(120_000);

    $exempt = ProductVariant::query()->where('sku', 'GRC-BANGUS-KG')->firstOrFail();
    expect($exempt->taxRate->name)->toBe('VAT Exempt');
    expect($exempt->taxRate->rate_micros)->toBe(0);

    // Stock went through the ledger, so stock_levels agrees with the movement sum.
    $level = DB::table('stock_levels')
        ->where('variant_id', $anchor->id)->where('location_id', $location->id)->first();
    expect((string) $level->qty)->toBe('20.000');
    expect(DB::table('stock_movements')->count())->toBe(200);
});
