<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class CreatePaymentMethodGroupInput
{
    public function __construct(
        public string $locationId,
        public string $code,
        public string $name,
        public string $driver,
        public int $sortOrder,
        public bool $isActive,
        public string $actorId,
    ) {}
}
