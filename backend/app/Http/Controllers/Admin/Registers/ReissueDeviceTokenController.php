<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Registers;

use App\Actions\Admin\Registers\ReissueDeviceToken;
use App\Http\Requests\Admin\Registers\ReissueDeviceTokenRequest;
use Illuminate\Http\JsonResponse;

final class ReissueDeviceTokenController
{
    public function __invoke(ReissueDeviceTokenRequest $request, ReissueDeviceToken $action): JsonResponse
    {
        return response()->json([
            'data' => ['token' => $action->execute($request->toInput())->deviceToken],
        ], 201);
    }
}
