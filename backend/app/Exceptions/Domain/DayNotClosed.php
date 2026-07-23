<?php
// backend/app/Exceptions/Domain/DayNotClosed.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Reopen was asked for a location-date with no closed business day. */
final class DayNotClosed extends DomainException
{
    public function __construct(private readonly string $locationId, private readonly string $businessDate)
    {
        parent::__construct('That day is not closed.');
    }

    public function errorCode(): string { return 'day_not_closed'; }
    public function httpStatus(): int { return 409; }
    public function details(): array { return ['location_id' => $this->locationId, 'business_date' => $this->businessDate]; }
}
