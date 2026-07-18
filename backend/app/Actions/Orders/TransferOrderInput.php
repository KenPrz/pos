<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class TransferOrderInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        public string $targetRegisterId,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
