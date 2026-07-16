<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class OpenOrderInput
{
    public function __construct(
        public string $registerId,
        public string $actorId,
        public ?string $tableRef,
        public ?string $customerId,
    ) {}
}
