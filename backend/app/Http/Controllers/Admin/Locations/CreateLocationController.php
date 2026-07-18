<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Locations;

use App\Actions\Admin\Locations\CreateLocation;
use App\Http\Requests\Admin\Locations\CreateLocationRequest;
use App\Http\Resources\Admin\AdminLocationResource;
use Illuminate\Http\JsonResponse;

final class CreateLocationController
{
    public function __invoke(CreateLocationRequest $request, CreateLocation $action): JsonResponse
    {
        return response()->json([
            'data' => ['location' => new AdminLocationResource($action->execute($request->toInput()))],
        ], 201);
    }
}
