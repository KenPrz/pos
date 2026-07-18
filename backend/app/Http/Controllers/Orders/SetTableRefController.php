<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\SetTableRef;
use App\Http\Requests\Orders\SetTableRefRequest;
use App\Http\Resources\OrderEnvelopeResource;
use Illuminate\Http\JsonResponse;

final class SetTableRefController
{
    public function __invoke(SetTableRefRequest $request, SetTableRef $action): JsonResponse
    {
        return (new OrderEnvelopeResource($action->execute($request->toInput())))->response();
    }
}
