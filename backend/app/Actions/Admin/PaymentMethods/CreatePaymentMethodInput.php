<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class CreatePaymentMethodInput
{
    public function __construct(
        public string $locationId,
        public string $groupId,
        public string $code,
        public string $name,
        public int $sortOrder,
        public bool $isActive,
        public string $actorId,
    ) {}
}
