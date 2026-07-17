<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class SetTableRefInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        public ?string $tableRef,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
