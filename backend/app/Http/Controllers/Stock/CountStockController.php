<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Actions\Stock\CountStock;
use App\Http\Requests\Stock\CountStockRequest;
use App\Http\Resources\StockLevelResource;
use Illuminate\Http\JsonResponse;

final class CountStockController
{
    public function __invoke(CountStockRequest $request, CountStock $action): JsonResponse
    {
        return (new StockLevelResource($action->execute($request->toInput())->level))->response()->setStatusCode(201);
    }
}
