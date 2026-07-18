<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateModifierGroup;
use App\Http\Requests\Admin\Catalog\CreateModifierGroupRequest;
use App\Http\Resources\Admin\AdminModifierGroupResource;
use Illuminate\Http\JsonResponse;

final class CreateModifierGroupController
{
    public function __invoke(CreateModifierGroupRequest $request, CreateModifierGroup $action): JsonResponse
    {
        return response()->json([
            'data' => ['modifier_group' => new AdminModifierGroupResource($action->execute($request->toInput()))],
        ], 201);
    }
}
