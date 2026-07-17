<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\SplitOrder;
use App\Http\Requests\Orders\SplitOrderRequest;
use App\Http\Resources\SplitOrderResource;
use Illuminate\Http\JsonResponse;

final class SplitOrderController
{
    public function __invoke(SplitOrderRequest $request, SplitOrder $action): JsonResponse
    {
        return (new SplitOrderResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
