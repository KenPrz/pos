<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class RemoveDiscountInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        public string $orderDiscountId,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
