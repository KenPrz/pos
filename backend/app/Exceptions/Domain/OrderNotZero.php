<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class OrderNotZero extends DomainException
{
    public function __construct(private readonly string $orderId, private readonly int $balanceCents)
    {
        parent::__construct('Only a zero-balance, zero-total order can be settled without a tender.');
    }

    public function errorCode(): string
    {
        return 'order_not_zero';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['order_id' => $this->orderId, 'balance_cents' => $this->balanceCents];
    }
}
