<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

final class CreateDiscountInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly ?int $percentMicros,
        public readonly ?int $amountCents,
        public readonly string $scope,
        public readonly bool $requiresSupervisor,
        public readonly bool $isActive,
        public readonly string $actorId,
    ) {}
}
