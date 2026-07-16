<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Actions\Stock\ReceiveStock;
use App\Http\Requests\Stock\ReceiveStockRequest;
use App\Http\Resources\StockLevelResource;
use Illuminate\Http\JsonResponse;

final class ReceiveStockController
{
    public function __invoke(ReceiveStockRequest $request, ReceiveStock $action): JsonResponse
    {
        return (new StockLevelResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
