<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\CloseShift;
use App\Http\Requests\Shifts\CloseShiftRequest;
use App\Http\Resources\CloseShiftResource;

final class CloseShiftController
{
    public function __invoke(CloseShiftRequest $request, CloseShift $action): CloseShiftResource
    {
        return new CloseShiftResource($action->execute($request->toInput()));
    }
}
