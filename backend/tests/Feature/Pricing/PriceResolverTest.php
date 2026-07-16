<?php

// tests/Feature/Pricing/PriceResolverTest.php
declare(strict_types=1);

use App\Domain\Pricing\PriceResolver;
use App\Models\Location;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

it('uses the base price when the location has no override', function (): void {
    $variant = ProductVariant::factory()->create(['price_cents' => 1999]);
    $location = Location::factory()->create();

    expect(app(PriceResolver::class)->for($variant, $location->id)->cents)->toBe(1999);
});

it('prefers the location override', function (): void {
    $variant = ProductVariant::factory()->create(['price_cents' => 1999]);
    $airport = Location::factory()->create();
    DB::table('variant_location_prices')->insert([
        'variant_id' => $variant->id,
        'location_id' => $airport->id,
        'price_cents' => 2499,
    ]);

    expect(app(PriceResolver::class)->for($variant, $airport->id)->cents)->toBe(2499);
});
