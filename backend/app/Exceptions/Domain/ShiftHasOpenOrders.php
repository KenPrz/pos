<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * A tab cannot outlive the drawer that's accountable for it. Close or transfer the
 * open orders before the shift they belong to can be closed.
 */
final class ShiftHasOpenOrders extends DomainException
{
    /** @param list<array{id: string, number: string}> $orders */
    public function __construct(private readonly string $shiftId, private readonly array $orders)
    {
        parent::__construct('Close or transfer the open orders first.');
    }

    public function errorCode(): string
    {
        return 'shift_has_open_orders';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['shift_id' => $this->shiftId, 'open_orders' => $this->orders];
    }
}
