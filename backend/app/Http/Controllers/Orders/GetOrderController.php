<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\GetOrder;
use App\Http\Requests\Orders\GetOrderRequest;
use App\Http\Resources\GetOrderResource;

final class GetOrderController
{
    public function __invoke(GetOrderRequest $request, GetOrder $action): GetOrderResource
    {
        return new GetOrderResource($action->execute(
            (string) $request->route('order'),
            $request->registerId(),
        ));
    }
}
