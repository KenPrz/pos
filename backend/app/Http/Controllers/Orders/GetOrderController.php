<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\GetOrder;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Resources\GetOrderResource;
use Illuminate\Http\Request;

final class GetOrderController
{
    public function __invoke(Request $request, GetOrder $action): GetOrderResource
    {
        return new GetOrderResource($action->execute(
            (string) $request->route('order'),
            $request->attributes->get(EnsureDeviceToken::REGISTER)->id,
        ));
    }
}
