<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateVariant;
use App\Http\Requests\Admin\Catalog\CreateVariantRequest;
use App\Http\Resources\Admin\AdminVariantResource;
use Illuminate\Http\JsonResponse;

final class CreateVariantController
{
    public function __invoke(CreateVariantRequest $request, CreateVariant $action): JsonResponse
    {
        return response()->json([
            'data' => ['variant' => new AdminVariantResource($action->execute($request->toInput()))],
        ], 201);
    }
}
