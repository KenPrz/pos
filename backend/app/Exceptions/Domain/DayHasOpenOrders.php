<?php
// backend/app/Exceptions/Domain/DayHasOpenOrders.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

/** A day cannot be closed with an order still open — close or transfer it first. */
final class DayHasOpenOrders extends DomainException
{
    /** @param list<array{id: string, number: string}> $openOrders */
    public function __construct(private readonly string $locationId, private readonly array $openOrders)
    {
        parent::__construct('Close or transfer every open order before closing the day.');
    }

    public function errorCode(): string { return 'day_has_open_orders'; }
    public function httpStatus(): int { return 409; }
    public function details(): array { return ['location_id' => $this->locationId, 'open_orders' => $this->openOrders]; }
}
