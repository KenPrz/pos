<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\ListModifierGroups;
use App\Http\Requests\Admin\Catalog\ListModifierGroupsRequest;
use App\Http\Resources\Admin\AdminModifierGroupResource;
use Illuminate\Http\JsonResponse;

final class ListModifierGroupsController
{
    public function __invoke(ListModifierGroupsRequest $request, ListModifierGroups $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminModifierGroupResource::collection($action->execute())],
        ]);
    }
}
