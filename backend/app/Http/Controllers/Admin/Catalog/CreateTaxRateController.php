<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateTaxRate;
use App\Http\Requests\Admin\Catalog\CreateTaxRateRequest;
use App\Http\Resources\Admin\AdminTaxRateResource;
use Illuminate\Http\JsonResponse;

final class CreateTaxRateController
{
    public function __invoke(CreateTaxRateRequest $request, CreateTaxRate $action): JsonResponse
    {
        return response()->json([
            'data' => ['tax_rate' => new AdminTaxRateResource($action->execute($request->toInput()))],
        ], 201);
    }
}
