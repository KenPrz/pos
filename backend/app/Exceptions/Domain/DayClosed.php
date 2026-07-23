<?php
// backend/app/Exceptions/Domain/DayClosed.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * A shift may not open on a closed business day. The single write-path guard of the
 * End-Of-Day feature; an admin must reopen the day first. See the End-Of-Day design.
 */
final class DayClosed extends DomainException
{
    public function __construct(private readonly string $locationId, private readonly string $businessDate)
    {
        parent::__construct('The business day is closed. Reopen it before opening a shift.');
    }

    public function errorCode(): string { return 'day_closed'; }
    public function httpStatus(): int { return 409; }
    public function details(): array { return ['location_id' => $this->locationId, 'business_date' => $this->businessDate]; }
}
