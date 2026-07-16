<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\VoidLine;
use App\Http\Requests\Orders\VoidLineRequest;
use App\Http\Resources\VoidLineResource;
use Illuminate\Http\JsonResponse;

final class VoidLineController
{
    public function __invoke(VoidLineRequest $request, VoidLine $action): JsonResponse
    {
        return (new VoidLineResource($action->execute($request->toInput())))->response();
    }
}
