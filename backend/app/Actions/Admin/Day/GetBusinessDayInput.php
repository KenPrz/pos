<?php
// backend/app/Actions/Admin/Day/GetBusinessDayInput.php
declare(strict_types=1);

namespace App\Actions\Admin\Day;

final readonly class GetBusinessDayInput
{
    public function __construct(
        public string $locationId,
        public string $businessDate,
    ) {}
}
