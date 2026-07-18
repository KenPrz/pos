<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateProduct;
use App\Http\Requests\Admin\Catalog\UpdateProductRequest;
use App\Http\Resources\Admin\AdminProductResource;
use Illuminate\Http\JsonResponse;

final class UpdateProductController
{
    public function __invoke(UpdateProductRequest $request, UpdateProduct $action): JsonResponse
    {
        return response()->json([
            'data' => ['product' => new AdminProductResource($action->execute($request->toInput()))],
        ]);
    }
}
