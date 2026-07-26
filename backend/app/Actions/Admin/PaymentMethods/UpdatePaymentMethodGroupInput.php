<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

final readonly class UpdatePaymentMethodGroupInput
{
    /** @param array<string, mixed> $changes only name, sort_order, is_active ever reach here */
    public function __construct(
        public string $groupId,
        public array $changes,
        public string $actorId,
    ) {}
}
