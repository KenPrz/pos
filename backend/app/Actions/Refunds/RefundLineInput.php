<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

final readonly class RefundLineInput
{
    public function __construct(
        public string $originalOrderLineId,
        public string $qty,            // numeric string; never float — see docs/03-api.md
        public bool $restock,
    ) {}
}
