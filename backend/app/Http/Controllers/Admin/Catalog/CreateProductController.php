<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateProduct;
use App\Http\Requests\Admin\Catalog\CreateProductRequest;
use App\Http\Resources\Admin\AdminProductResource;
use Illuminate\Http\JsonResponse;

final class CreateProductController
{
    public function __invoke(CreateProductRequest $request, CreateProduct $action): JsonResponse
    {
        return response()->json([
            'data' => ['product' => new AdminProductResource($action->execute($request->toInput()))],
        ], 201);
    }
}
