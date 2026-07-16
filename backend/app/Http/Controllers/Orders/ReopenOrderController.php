<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\ReopenOrder;
use App\Http\Requests\Orders\ReopenOrderRequest;
use App\Http\Resources\ReopenOrderResource;
use Illuminate\Http\JsonResponse;

final class ReopenOrderController
{
    public function __invoke(ReopenOrderRequest $request, ReopenOrder $action): JsonResponse
    {
        return (new ReopenOrderResource($action->execute($request->toInput())))->response();
    }
}
