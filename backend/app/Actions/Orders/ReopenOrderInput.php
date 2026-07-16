<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class ReopenOrderInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        public string $reason,
        public string $actorId,
    ) {}
}
