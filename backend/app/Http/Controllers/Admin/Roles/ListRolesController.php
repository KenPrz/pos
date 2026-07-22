<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Roles;

use App\Actions\Admin\Roles\ListRoles;
use App\Http\Requests\Admin\Roles\ListRolesRequest;
use App\Http\Resources\Admin\AdminRoleResource;
use Illuminate\Http\JsonResponse;

final class ListRolesController
{
    public function __invoke(ListRolesRequest $request, ListRoles $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminRoleResource::collection($action->execute())],
        ]);
    }
}
