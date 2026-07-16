<?php

declare(strict_types=1);

namespace App\Actions\Payments;

final readonly class VoidPaymentInput
{
    public function __construct(
        public string $paymentId,
        public string $registerId,
        public string $reason,
        public string $actorId,
    ) {}
}
