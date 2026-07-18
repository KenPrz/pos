<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\ListCategories;
use App\Http\Requests\Admin\Catalog\ListCategoriesRequest;
use App\Http\Resources\Admin\AdminCategoryResource;
use Illuminate\Http\JsonResponse;

final class ListCategoriesController
{
    public function __invoke(ListCategoriesRequest $request, ListCategories $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminCategoryResource::collection($action->execute())],
        ]);
    }
}
