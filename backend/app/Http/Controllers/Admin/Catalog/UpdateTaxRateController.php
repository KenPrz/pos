<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateTaxRate;
use App\Http\Requests\Admin\Catalog\UpdateTaxRateRequest;
use App\Http\Resources\Admin\AdminTaxRateResource;
use Illuminate\Http\JsonResponse;

final class UpdateTaxRateController
{
    public function __invoke(UpdateTaxRateRequest $request, UpdateTaxRate $action): JsonResponse
    {
        return response()->json([
            'data' => ['tax_rate' => new AdminTaxRateResource($action->execute($request->toInput()))],
        ]);
    }
}
