<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class VoidLineInput
{
    public function __construct(
        public string $orderId,
        public string $lineId,
        public string $registerId,
        public string $reason,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
