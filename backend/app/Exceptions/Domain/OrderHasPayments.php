<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Voiding an order with money already applied would silently orphan that money — the
 * payments are append-only records of a real tender that happened. The operator must
 * void the payments first (a separate, audited step), so the till and the ledger never
 * disagree about what was actually taken.
 */
final class OrderHasPayments extends DomainException
{
    public function __construct(
        private readonly string $orderId,
        private readonly int $paidCents,
    ) {
        parent::__construct('Void the payments first.');
    }

    public function errorCode(): string
    {
        return 'order_has_payments';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'order_id' => $this->orderId,
            'paid_cents' => $this->paidCents,
        ];
    }
}
