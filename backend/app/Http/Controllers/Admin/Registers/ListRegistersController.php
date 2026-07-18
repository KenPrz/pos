<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Registers;

use App\Actions\Admin\Registers\ListRegisters;
use App\Http\Requests\Admin\Registers\ListRegistersRequest;
use App\Http\Resources\Admin\AdminRegisterResource;
use Illuminate\Http\JsonResponse;

final class ListRegistersController
{
    public function __invoke(ListRegistersRequest $request, ListRegisters $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminRegisterResource::collection($action->execute())],
        ]);
    }
}
