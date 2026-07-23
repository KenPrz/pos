<?php
// backend/app/Exceptions/Domain/DayHasOpenShifts.php
declare(strict_types=1);

namespace App\Exceptions\Domain;

/** A day cannot be closed while a drawer is still open — count every shift first. */
final class DayHasOpenShifts extends DomainException
{
    /** @param list<array{register_id: string, register_name: string, shift_id: string}> $openShifts */
    public function __construct(private readonly string $locationId, private readonly array $openShifts)
    {
        parent::__construct('Close every open shift before closing the day.');
    }

    public function errorCode(): string { return 'day_has_open_shifts'; }
    public function httpStatus(): int { return 409; }
    public function details(): array { return ['location_id' => $this->locationId, 'open_shifts' => $this->openShifts]; }
}
