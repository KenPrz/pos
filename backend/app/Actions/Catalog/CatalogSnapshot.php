<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

final readonly class CatalogSnapshot
{
    public function __construct(
        public array $categories,
        public array $products,
        public array $variants,
        public array $modifierGroups,
        public array $modifiers,
        public array $taxRates,
        public array $discounts,
    ) {}
}
