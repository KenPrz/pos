<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateModifier;
use App\Http\Requests\Admin\Catalog\CreateModifierRequest;
use App\Http\Resources\Admin\AdminModifierResource;
use Illuminate\Http\JsonResponse;

final class CreateModifierController
{
    public function __invoke(CreateModifierRequest $request, CreateModifier $action): JsonResponse
    {
        return response()->json([
            'data' => ['modifier' => new AdminModifierResource($action->execute($request->toInput()))],
        ], 201);
    }
}
