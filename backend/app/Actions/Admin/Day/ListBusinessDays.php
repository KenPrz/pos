<?php
// backend/app/Actions/Admin/Day/ListBusinessDays.php
declare(strict_types=1);

namespace App\Actions\Admin\Day;

use App\Models\BusinessDay;
use Illuminate\Database\Eloquent\Collection;

/** The day-close record for a location — most recent first, last 60 days. */
final class ListBusinessDays
{
    private const int LIMIT = 60;

    /** @return Collection<int, BusinessDay> */
    public function execute(string $locationId): Collection
    {
        return BusinessDay::query()
            ->where('location_id', $locationId)
            ->orderByDesc('business_date')
            ->limit(self::LIMIT)
            ->get();
    }
}
