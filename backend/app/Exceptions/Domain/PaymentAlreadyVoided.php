<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * A double void — the payment is no longer `captured`, so there is nothing left to
 * take back out of the order's `paid_cents`. The row itself is append-only; only a
 * `captured` payment can transition to `voided`, once.
 */
final class PaymentAlreadyVoided extends DomainException
{
    public function __construct(private readonly string $paymentId)
    {
        parent::__construct('This payment has already been voided.');
    }

    public function errorCode(): string
    {
        return 'payment_already_voided';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['payment_id' => $this->paymentId];
    }
}
