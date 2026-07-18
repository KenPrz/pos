<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\ListDiscounts;
use App\Http\Requests\Admin\Catalog\ListDiscountsRequest;
use App\Http\Resources\Admin\AdminDiscountResource;
use Illuminate\Http\JsonResponse;

final class ListDiscountsController
{
    public function __invoke(ListDiscountsRequest $request, ListDiscounts $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminDiscountResource::collection($action->execute())],
        ]);
    }
}
