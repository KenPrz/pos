<?php

declare(strict_types=1);

namespace App\Actions\Stock;

final readonly class AdjustStockInput
{
    public function __construct(
        public string $variantId,
        public string $registerId,
        public string $qtyDelta,   // signed numeric string; never float — see 03-api.md
        public string $reason,     // 'adjustment' or 'waste'
        public ?string $note,
        public string $actorId,
    ) {}
}
