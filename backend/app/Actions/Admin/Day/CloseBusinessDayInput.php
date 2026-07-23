<?php
// backend/app/Actions/Admin/Day/CloseBusinessDayInput.php
declare(strict_types=1);

namespace App\Actions\Admin\Day;

final readonly class CloseBusinessDayInput
{
    /** @param array<string, mixed> $checklist */
    public function __construct(
        public string $locationId,
        public string $businessDate,   // 'YYYY-MM-DD', the location's local day
        public int $depositCents,
        public array $checklist,
        public ?string $note,
        public string $actorId,
    ) {}
}
