<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Registers;

use App\Actions\Admin\Registers\UpdateRegister;
use App\Http\Requests\Admin\Registers\UpdateRegisterRequest;
use App\Http\Resources\Admin\AdminRegisterResource;
use Illuminate\Http\JsonResponse;

final class UpdateRegisterController
{
    public function __invoke(UpdateRegisterRequest $request, UpdateRegister $action): JsonResponse
    {
        return response()->json([
            'data' => ['register' => new AdminRegisterResource($action->execute($request->toInput()))],
        ]);
    }
}
