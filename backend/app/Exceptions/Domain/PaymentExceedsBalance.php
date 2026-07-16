<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The amount being applied to this payment is more than the order still owes.
 *
 * Not the same as overpayment-as-a-feature: for cash, handing over more than the
 * balance is change, computed by Tender — never a payment recorded above the balance.
 * A driver that "overpays" and refunds the difference is two mutations standing in for
 * one correct one.
 */
final class PaymentExceedsBalance extends DomainException
{
    public function __construct(
        private readonly string $orderId,
        private readonly int $amountCents,
        private readonly int $balanceCents,
    ) {
        parent::__construct('For cash, the overage is change, not payment.');
    }

    public function errorCode(): string
    {
        return 'payment_exceeds_balance';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'order_id' => $this->orderId,
            'amount_cents' => $this->amountCents,
            'balance_cents' => $this->balanceCents,
        ];
    }
}
