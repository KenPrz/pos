<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\UpdateLineQty;
use App\Http\Requests\Orders\UpdateLineQtyRequest;
use App\Http\Resources\AddLineResource;
use Illuminate\Http\JsonResponse;

final class UpdateLineQtyController
{
    public function __invoke(UpdateLineQtyRequest $request, UpdateLineQty $action): JsonResponse
    {
        return (new AddLineResource($action->execute($request->toInput())))->response()->setStatusCode(200);
    }
}
