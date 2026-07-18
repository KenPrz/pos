<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Admin\Catalog\ListModifiers;
use App\Http\Requests\Admin\Catalog\ListModifiersRequest;
use App\Http\Resources\Admin\AdminModifierResource;
use Illuminate\Http\JsonResponse;

final class ListModifiersController
{
    public function __invoke(ListModifiersRequest $request, ListModifiers $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminModifierResource::collection($action->execute())],
        ]);
    }
}
