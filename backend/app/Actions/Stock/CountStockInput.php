<?php

declare(strict_types=1);

namespace App\Actions\Stock;

final readonly class CountStockInput
{
    public function __construct(
        public string $variantId,
        public string $registerId,
        public string $countedQty,   // non-negative numeric string; never float — see 03-api.md
        public ?string $note,
        public string $actorId,
    ) {}
}
