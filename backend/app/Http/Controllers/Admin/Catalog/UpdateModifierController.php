<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateModifier;
use App\Http\Requests\Admin\Catalog\UpdateModifierRequest;
use App\Http\Resources\Admin\AdminModifierResource;
use Illuminate\Http\JsonResponse;

final class UpdateModifierController
{
    public function __invoke(UpdateModifierRequest $request, UpdateModifier $action): JsonResponse
    {
        return response()->json([
            'data' => ['modifier' => new AdminModifierResource($action->execute($request->toInput()))],
        ]);
    }
}
