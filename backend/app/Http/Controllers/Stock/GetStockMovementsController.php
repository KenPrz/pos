<?php

declare(strict_types=1);

namespace App\Http\Controllers\Stock;

use App\Actions\Stock\GetStockMovements;
use App\Http\Requests\Stock\GetStockMovementsRequest;
use App\Http\Resources\StockMovementsResource;

final class GetStockMovementsController
{
    public function __invoke(GetStockMovementsRequest $request, GetStockMovements $action): StockMovementsResource
    {
        return new StockMovementsResource($action->execute($request->variantId(), $request->registerId()));
    }
}
