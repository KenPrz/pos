<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\OpenOrder;
use App\Http\Requests\Orders\OpenOrderRequest;
use App\Http\Resources\OpenOrderResource;
use Illuminate\Http\JsonResponse;

final class OpenOrderController
{
    public function __invoke(OpenOrderRequest $request, OpenOrder $action): JsonResponse
    {
        return (new OpenOrderResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
