<?php

declare(strict_types=1);

namespace App\Actions\Payments;

final readonly class TakePaymentInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        public string $driver,
        public int $amountCents,
        public ?int $tenderedCents,
        public ?string $reference,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
