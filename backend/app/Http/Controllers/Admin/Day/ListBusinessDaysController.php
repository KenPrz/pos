<?php
// backend/app/Http/Controllers/Admin/Day/ListBusinessDaysController.php
declare(strict_types=1);

namespace App\Http\Controllers\Admin\Day;

use App\Actions\Admin\Day\ListBusinessDays;
use App\Http\Requests\Admin\Day\GetBusinessDayRequest;
use App\Http\Resources\Admin\BusinessDayResource;
use Illuminate\Http\JsonResponse;

/**
 * Reuses GetBusinessDayRequest purely for its authorize()/location-scope; the `date`
 * rule is harmless here (unused).
 */
final class ListBusinessDaysController
{
    public function __invoke(GetBusinessDayRequest $request, ListBusinessDays $action): JsonResponse
    {
        $rows = $action->execute((string) $request->route('location'));

        return response()->json([
            'data' => ['items' => BusinessDayResource::collection($rows)],
        ]);
    }
}
