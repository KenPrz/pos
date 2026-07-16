<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\RemoveDiscount;
use App\Http\Requests\Orders\RemoveDiscountRequest;
use App\Http\Resources\RemoveDiscountResource;
use Illuminate\Http\JsonResponse;

final class RemoveDiscountController
{
    public function __invoke(RemoveDiscountRequest $request, RemoveDiscount $action): JsonResponse
    {
        return (new RemoveDiscountResource($action->execute($request->toInput())))->response();
    }
}
