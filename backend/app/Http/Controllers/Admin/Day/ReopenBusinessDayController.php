<?php
// backend/app/Http/Controllers/Admin/Day/ReopenBusinessDayController.php
declare(strict_types=1);

namespace App\Http\Controllers\Admin\Day;

use App\Actions\Admin\Day\ReopenBusinessDay;
use App\Http\Requests\Admin\Day\ReopenBusinessDayRequest;
use App\Http\Resources\Admin\BusinessDayResource;

final class ReopenBusinessDayController
{
    public function __invoke(ReopenBusinessDayRequest $request, ReopenBusinessDay $action): BusinessDayResource
    {
        return new BusinessDayResource($action->execute($request->toInput()));
    }
}
