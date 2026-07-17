<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Actions\Stock\AdjustStock;
use App\Http\Requests\Stock\AdjustStockRequest;
use App\Http\Resources\StockLevelResource;
use Illuminate\Http\JsonResponse;

final class AdjustStockController
{
    public function __invoke(AdjustStockRequest $request, AdjustStock $action): JsonResponse
    {
        return (new StockLevelResource($action->execute($request->toInput())))->response()->setStatusCode(201);
    }
}
