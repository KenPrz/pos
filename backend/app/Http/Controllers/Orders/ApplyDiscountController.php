<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\ApplyDiscount;
use App\Http\Requests\Orders\ApplyDiscountRequest;
use App\Http\Resources\ApplyDiscountResource;
use Illuminate\Http\JsonResponse;

final class ApplyDiscountController
{
    public function __invoke(ApplyDiscountRequest $request, ApplyDiscount $action): JsonResponse
    {
        return (new ApplyDiscountResource($action->execute($request->toInput())))->response();
    }
}
