<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateCategory;
use App\Http\Requests\Admin\Catalog\CreateCategoryRequest;
use App\Http\Resources\Admin\AdminCategoryResource;
use Illuminate\Http\JsonResponse;

final class CreateCategoryController
{
    public function __invoke(CreateCategoryRequest $request, CreateCategory $action): JsonResponse
    {
        return response()->json([
            'data' => ['category' => new AdminCategoryResource($action->execute($request->toInput()))],
        ], 201);
    }
}
