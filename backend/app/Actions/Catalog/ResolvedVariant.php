<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Money\Money;
use App\Models\ProductVariant;

final readonly class ResolvedVariant
{
    public function __construct(
        public ProductVariant $variant,
        public Money $price,
    ) {}
}
