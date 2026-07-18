<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\ListProducts;
use App\Http\Requests\Admin\Catalog\ListProductsRequest;
use App\Http\Resources\Admin\AdminProductResource;
use Illuminate\Http\JsonResponse;

final class ListProductsController
{
    public function __invoke(ListProductsRequest $request, ListProducts $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminProductResource::collection($action->execute())],
        ]);
    }
}
