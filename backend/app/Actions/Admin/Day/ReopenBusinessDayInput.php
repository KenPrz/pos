<?php
// backend/app/Actions/Admin/Day/ReopenBusinessDayInput.php
declare(strict_types=1);

namespace App\Actions\Admin\Day;

final readonly class ReopenBusinessDayInput
{
    public function __construct(
        public string $locationId,
        public string $businessDate,
        public string $reason,
        public string $actorId,
    ) {}
}
