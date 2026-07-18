<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\TransferOrder;
use App\Http\Requests\Orders\TransferOrderRequest;
use App\Http\Resources\OrderEnvelopeResource;
use Illuminate\Http\JsonResponse;

final class TransferOrderController
{
    public function __invoke(TransferOrderRequest $request, TransferOrder $action): JsonResponse
    {
        return (new OrderEnvelopeResource($action->execute($request->toInput())))->response();
    }
}
