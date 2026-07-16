<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\OpenShift;
use App\Http\Requests\Shifts\OpenShiftRequest;
use App\Http\Resources\OpenShiftResource;
use Illuminate\Http\JsonResponse;

final class OpenShiftController
{
    public function __invoke(OpenShiftRequest $request, OpenShift $action): JsonResponse
    {
        return (new OpenShiftResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
