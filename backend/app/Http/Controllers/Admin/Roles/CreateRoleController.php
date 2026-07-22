<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Roles;

use App\Actions\Admin\Roles\CreateRole;
use App\Http\Requests\Admin\Roles\CreateRoleRequest;
use App\Http\Resources\Admin\AdminRoleResource;
use Illuminate\Http\JsonResponse;

final class CreateRoleController
{
    public function __invoke(CreateRoleRequest $request, CreateRole $action): JsonResponse
    {
        return response()->json([
            'data' => ['role' => new AdminRoleResource($action->execute($request->toInput()))],
        ], 201);
    }
}
