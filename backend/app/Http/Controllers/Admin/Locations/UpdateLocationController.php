<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Locations;

use App\Actions\Admin\Locations\UpdateLocation;
use App\Http\Requests\Admin\Locations\UpdateLocationRequest;
use App\Http\Resources\Admin\AdminLocationResource;
use Illuminate\Http\JsonResponse;

final class UpdateLocationController
{
    public function __invoke(UpdateLocationRequest $request, UpdateLocation $action): JsonResponse
    {
        return response()->json([
            'data' => ['location' => new AdminLocationResource($action->execute($request->toInput()))],
        ]);
    }
}
