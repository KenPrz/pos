<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateVariant;
use App\Http\Requests\Admin\Catalog\UpdateVariantRequest;
use App\Http\Resources\Admin\AdminVariantResource;
use Illuminate\Http\JsonResponse;

final class UpdateVariantController
{
    public function __invoke(UpdateVariantRequest $request, UpdateVariant $action): JsonResponse
    {
        return response()->json([
            'data' => ['variant' => new AdminVariantResource($action->execute($request->toInput()))],
        ]);
    }
}
