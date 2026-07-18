<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\ListTaxRates;
use App\Http\Requests\Admin\Catalog\ListTaxRatesRequest;
use App\Http\Resources\Admin\AdminTaxRateResource;
use Illuminate\Http\JsonResponse;

final class ListTaxRatesController
{
    public function __invoke(ListTaxRatesRequest $request, ListTaxRates $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminTaxRateResource::collection($action->execute())],
        ]);
    }
}
