<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class ApplyDiscountInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        public string $discountId,
        public ?string $orderLineId,
        public string $reason,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
