<?php
// backend/app/Http/Controllers/Admin/Day/ListBusinessDaysController.php
declare(strict_types=1);

namespace App\Http\Controllers\Admin\Day;

use App\Http\Requests\Admin\Day\GetBusinessDayRequest;
use App\Http\Resources\Admin\BusinessDayResource;
use App\Models\BusinessDay;

/**
 * The day-close record for a location — most recent first, last 60 days. Reuses
 * GetBusinessDayRequest purely for its authorize()/location-scope; the `date` rule is
 * harmless here (unused).
 */
final class ListBusinessDaysController
{
    public function __invoke(GetBusinessDayRequest $request): object
    {
        $rows = BusinessDay::query()
            ->where('location_id', (string) $request->route('location'))
            ->orderByDesc('business_date')
            ->limit(60)
            ->get();

        return BusinessDayResource::collection($rows);
    }
}
