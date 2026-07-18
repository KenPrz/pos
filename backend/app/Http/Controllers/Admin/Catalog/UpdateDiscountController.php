<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateDiscount;
use App\Http\Requests\Admin\Catalog\UpdateDiscountRequest;
use App\Http\Resources\Admin\AdminDiscountResource;
use Illuminate\Http\JsonResponse;

final class UpdateDiscountController
{
    public function __invoke(UpdateDiscountRequest $request, UpdateDiscount $action): JsonResponse
    {
        return response()->json([
            'data' => ['discount' => new AdminDiscountResource($action->execute($request->toInput()))],
        ]);
    }
}
