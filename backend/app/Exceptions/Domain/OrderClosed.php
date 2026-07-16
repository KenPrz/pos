<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The order is closed or voided; its lines are frozen. A closed order is never
 * mutated — see docs/00-overview.md — so any write attempt after the fact is a bug in
 * the caller, not a state this action can quietly accept.
 */
final class OrderClosed extends DomainException
{
    public function __construct(private readonly string $orderId, private readonly string $status)
    {
        parent::__construct("This order is {$status} and cannot be changed.");
    }

    public function errorCode(): string
    {
        return 'order_closed';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['order_id' => $this->orderId, 'status' => $this->status];
    }
}
