<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Shifts;

use App\Actions\Admin\Shifts\ListPendingVariances;
use App\Http\Requests\Admin\Shifts\ListPendingVariancesRequest;
use App\Http\Resources\Admin\AdminPendingVarianceResource;
use Illuminate\Http\JsonResponse;

final class ListPendingVariancesController
{
    public function __invoke(ListPendingVariancesRequest $request, ListPendingVariances $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminPendingVarianceResource::collection($action->execute($request->toInput()))],
        ]);
    }
}
