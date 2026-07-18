<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shifts;

use App\Actions\Shifts\ListOpenShiftRegisters;
use App\Http\Requests\Shifts\OpenShiftRegistersRequest;
use Illuminate\Http\JsonResponse;

final class OpenShiftRegistersController
{
    public function __invoke(OpenShiftRegistersRequest $request, ListOpenShiftRegisters $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => $action->execute($request->registerId())],
        ]);
    }
}
