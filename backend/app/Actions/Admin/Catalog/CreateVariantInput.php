<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class CreateVariantInput
{
    public function __construct(
        public readonly string $productId,
        public readonly string $name,
        public readonly string $sku,
        public readonly ?string $barcode,
        public readonly int $priceCents,
        public readonly ?int $costCents,
        public readonly ?string $taxRateId,
        public readonly bool $trackInventory,
        public readonly string $actorId,
    ) {}
}
