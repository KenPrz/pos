<?php

// app/Domain/Pricing/PriceResolver.php
declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money\Money;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Location override, else the variant's base price. Pricing resolution lives here and
 * nowhere else — the register never implements it. See docs/02-data-model.md.
 */
final class PriceResolver
{
    public function for(ProductVariant $variant, string $locationId): Money
    {
        $override = DB::table('variant_location_prices')
            ->where('variant_id', $variant->id)
            ->where('location_id', $locationId)
            ->value('price_cents');

        return Money::fromCents($override !== null ? (int) $override : $variant->price_cents);
    }
}
