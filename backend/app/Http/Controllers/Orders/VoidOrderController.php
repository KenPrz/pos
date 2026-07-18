<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\VoidOrder;
use App\Http\Requests\Orders\VoidOrderRequest;
use App\Http\Resources\OrderEnvelopeResource;
use Illuminate\Http\JsonResponse;

final class VoidOrderController
{
    public function __invoke(VoidOrderRequest $request, VoidOrder $action): JsonResponse
    {
        return (new OrderEnvelopeResource($action->execute($request->toInput())))->response();
    }
}
