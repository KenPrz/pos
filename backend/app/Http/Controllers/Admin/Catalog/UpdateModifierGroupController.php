<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateModifierGroup;
use App\Http\Requests\Admin\Catalog\UpdateModifierGroupRequest;
use App\Http\Resources\Admin\AdminModifierGroupResource;
use Illuminate\Http\JsonResponse;

final class UpdateModifierGroupController
{
    public function __invoke(UpdateModifierGroupRequest $request, UpdateModifierGroup $action): JsonResponse
    {
        return response()->json([
            'data' => ['modifier_group' => new AdminModifierGroupResource($action->execute($request->toInput()))],
        ]);
    }
}
