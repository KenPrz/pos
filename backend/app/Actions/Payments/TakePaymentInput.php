<?php

declare(strict_types=1);

namespace App\Actions\Payments;

final readonly class TakePaymentInput
{
    public function __construct(
        public string $orderId,
        public string $registerId,
        // The per-location method code. The driver is DERIVED from the method's group
        // (PaymentMethodResolver) — a caller never names a driver.
        public string $paymentMethodCode,
        public int $amountCents,
        public ?int $tenderedCents,
        public ?string $reference,
        public int $expectedVersion,
        public string $actorId,
    ) {}
}
