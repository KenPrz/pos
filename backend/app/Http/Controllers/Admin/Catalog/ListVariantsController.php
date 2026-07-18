<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\ListVariants;
use App\Http\Requests\Admin\Catalog\ListVariantsRequest;
use App\Http\Resources\Admin\AdminVariantResource;
use Illuminate\Http\JsonResponse;

final class ListVariantsController
{
    public function __invoke(ListVariantsRequest $request, ListVariants $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminVariantResource::collection($action->execute())],
        ]);
    }
}
