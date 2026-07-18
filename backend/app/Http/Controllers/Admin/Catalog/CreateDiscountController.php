<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateDiscount;
use App\Http\Requests\Admin\Catalog\CreateDiscountRequest;
use App\Http\Resources\Admin\AdminDiscountResource;
use Illuminate\Http\JsonResponse;

final class CreateDiscountController
{
    public function __invoke(CreateDiscountRequest $request, CreateDiscount $action): JsonResponse
    {
        return response()->json([
            'data' => ['discount' => new AdminDiscountResource($action->execute($request->toInput()))],
        ], 201);
    }
}
