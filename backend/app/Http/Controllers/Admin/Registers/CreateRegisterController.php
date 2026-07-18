<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Registers;

use App\Actions\Admin\Registers\CreateRegister;
use App\Http\Requests\Admin\Registers\CreateRegisterRequest;
use App\Http\Resources\Admin\AdminRegisterResource;
use Illuminate\Http\JsonResponse;

final class CreateRegisterController
{
    public function __invoke(CreateRegisterRequest $request, CreateRegister $action): JsonResponse
    {
        return response()->json([
            'data' => ['register' => new AdminRegisterResource($action->execute($request->toInput()))],
        ], 201);
    }
}
