<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\SettleZeroOrder;
use App\Http\Requests\Orders\SettleZeroOrderRequest;
use App\Http\Resources\SettleZeroOrderResource;

final class SettleZeroOrderController
{
    public function __invoke(SettleZeroOrderRequest $request, SettleZeroOrder $action): SettleZeroOrderResource
    {
        return new SettleZeroOrderResource($action->execute($request->toInput()));
    }
}
