<?php
// backend/app/Exceptions/Domain/DayAlreadyClosed.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Close was asked for a location-date that is already closed and not reopened since. */
final class DayAlreadyClosed extends DomainException
{
    public function __construct(private readonly string $locationId, private readonly string $businessDate)
    {
        parent::__construct('That day is already closed.');
    }

    public function errorCode(): string { return 'day_already_closed'; }
    public function httpStatus(): int { return 409; }
    public function details(): array { return ['location_id' => $this->locationId, 'business_date' => $this->businessDate]; }
}
