<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The shift that took this payment has already closed and reconciled its drawer.
 * Voiding after that would change expected cash after the count already happened,
 * making the recorded variance a lie about a drawer that no longer exists. Before
 * shift close only.
 */
final class PaymentShiftClosed extends DomainException
{
    public function __construct(
        private readonly string $paymentId,
        private readonly string $shiftId,
    ) {
        parent::__construct('This payment\'s shift has already closed; void before shift close only.');
    }

    public function errorCode(): string
    {
        return 'payment_shift_closed';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['payment_id' => $this->paymentId, 'shift_id' => $this->shiftId];
    }
}
