<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Locations;

use App\Actions\Admin\Locations\ListLocations;
use App\Http\Requests\Admin\Locations\ListLocationsRequest;
use App\Http\Resources\Admin\AdminLocationResource;
use Illuminate\Http\JsonResponse;

final class ListLocationsController
{
    public function __invoke(ListLocationsRequest $request, ListLocations $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminLocationResource::collection($action->execute())],
        ]);
    }
}
