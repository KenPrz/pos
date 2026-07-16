<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\GetCurrentShift;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Resources\CurrentShiftResource;
use Illuminate\Http\Request;

final class CurrentShiftController
{
    public function __invoke(Request $request, GetCurrentShift $action): CurrentShiftResource
    {
        return new CurrentShiftResource($action->execute($request->attributes->get(EnsureDeviceToken::REGISTER)->id));
    }
}
