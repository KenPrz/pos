<?php

declare(strict_types=1);

namespace App\Http\Controllers\Refunds;

use App\Actions\Refunds\RefundOrder;
use App\Http\Requests\Refunds\RefundOrderRequest;
use App\Http\Resources\RefundResource;
use Illuminate\Http\JsonResponse;

final class RefundOrderController
{
    public function __invoke(RefundOrderRequest $request, RefundOrder $action): JsonResponse
    {
        return (new RefundResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
