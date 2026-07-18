<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateCategory;
use App\Http\Requests\Admin\Catalog\UpdateCategoryRequest;
use App\Http\Resources\Admin\AdminCategoryResource;
use Illuminate\Http\JsonResponse;

final class UpdateCategoryController
{
    public function __invoke(UpdateCategoryRequest $request, UpdateCategory $action): JsonResponse
    {
        return response()->json([
            'data' => ['category' => new AdminCategoryResource($action->execute($request->toInput()))],
        ]);
    }
}
