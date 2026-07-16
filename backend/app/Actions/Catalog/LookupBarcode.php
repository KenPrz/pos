<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Pricing\PriceResolver;
use App\Models\ProductVariant;

/**
 * The scanner path: the hottest read in retail, kept to indexed lookups. 404 renders
 * through the standard envelope.
 */
final class LookupBarcode
{
    public function __construct(private readonly PriceResolver $prices) {}

    public function execute(string $barcode, string $locationId): ResolvedVariant
    {
        $variant = ProductVariant::query()->active()
            ->where('barcode', $barcode)
            ->with('product')
            ->firstOrFail();

        return new ResolvedVariant($variant, $this->prices->for($variant, $locationId));
    }
}
