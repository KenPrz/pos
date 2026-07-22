<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Roles;

use App\Actions\Admin\Roles\UpdateRole;
use App\Http\Requests\Admin\Roles\UpdateRoleRequest;
use App\Http\Resources\Admin\AdminRoleResource;
use Illuminate\Http\JsonResponse;

final class UpdateRoleController
{
    public function __invoke(UpdateRoleRequest $request, UpdateRole $action): JsonResponse
    {
        return response()->json([
            'data' => ['role' => new AdminRoleResource($action->execute($request->toInput()))],
        ]);
    }
}
