<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\SetLinePrepState;
use App\Http\Requests\Orders\SetLinePrepStateRequest;
use App\Http\Resources\AddLineResource;
use Illuminate\Http\JsonResponse;

final class SetLinePrepStateController
{
    public function __invoke(SetLinePrepStateRequest $request, SetLinePrepState $action): JsonResponse
    {
        return (new AddLineResource($action->execute($request->toInput())))->response()->setStatusCode(200);
    }
}
