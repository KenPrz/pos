<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\SetProductModifierGroups;
use App\Http\Requests\Admin\Catalog\SetProductModifierGroupsRequest;
use App\Http\Resources\Admin\AdminProductResource;
use Illuminate\Http\JsonResponse;

final class SetProductModifierGroupsController
{
    public function __invoke(SetProductModifierGroupsRequest $request, SetProductModifierGroups $action): JsonResponse
    {
        return response()->json([
            'data' => ['product' => new AdminProductResource($action->execute($request->toInput()))],
        ]);
    }
}
