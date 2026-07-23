<?php
// backend/app/Http/Controllers/Admin/Day/CloseBusinessDayController.php
declare(strict_types=1);

namespace App\Http\Controllers\Admin\Day;

use App\Actions\Admin\Day\CloseBusinessDay;
use App\Http\Requests\Admin\Day\CloseBusinessDayRequest;
use App\Http\Resources\Admin\BusinessDayResource;
use Illuminate\Http\JsonResponse;

final class CloseBusinessDayController
{
    public function __invoke(CloseBusinessDayRequest $request, CloseBusinessDay $action): JsonResponse
    {
        // Force 200, not Laravel's default 201: CloseBusinessDay uses updateOrCreate, so
        // the resource's underlying model reports wasRecentlyCreated=true on a location's
        // very first close and ResourceResponse::calculateStatus() would otherwise return
        // 201 for this POST. This is a day-close action, not a REST "create" — every
        // caller (including re-close of a reopened day, which never creates) gets the
        // same 200.
        return (new BusinessDayResource($action->execute($request->toInput())))
            ->response()
            ->setStatusCode(200);
    }
}
