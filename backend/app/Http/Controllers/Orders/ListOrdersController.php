<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\ListOrders;
use App\Http\Requests\Orders\ListOrdersRequest;
use App\Http\Resources\ListOrdersResource;

final class ListOrdersController
{
    public function __invoke(ListOrdersRequest $request, ListOrders $action): ListOrdersResource
    {
        return new ListOrdersResource($action->execute(
            $request->registerId(),
            $request->string('number')->toString() ?: null,
            $request->string('status')->toString() ?: null,
        ));
    }
}
