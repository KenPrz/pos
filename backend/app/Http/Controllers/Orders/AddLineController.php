<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\AddLineToOrder;
use App\Http\Requests\Orders\AddLineRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

final class AddLineController
{
    public function __invoke(AddLineRequest $request, AddLineToOrder $action): JsonResponse
    {
        return (new OrderResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
