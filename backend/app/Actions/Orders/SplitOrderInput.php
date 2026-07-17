<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class SplitOrderInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        public int $ways,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
