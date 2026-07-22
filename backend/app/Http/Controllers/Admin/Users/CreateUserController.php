<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Users;

use App\Actions\Admin\Users\CreateUser;
use App\Domain\Rbac\PermissionAssignments;
use App\Domain\Rbac\RoleAssignments;
use App\Http\Requests\Admin\Users\CreateUserRequest;
use App\Http\Resources\Admin\AdminUserResource;
use Illuminate\Http\JsonResponse;

final class CreateUserController
{
    public function __invoke(CreateUserRequest $request, CreateUser $action, RoleAssignments $roles, PermissionAssignments $permissions): JsonResponse
    {
        $user = $action->execute($request->toInput());
        $user->setAttribute('role_assignments', $roles->describe($user));
        $user->setAttribute('permission_assignments', $permissions->describe($user));

        return response()->json([
            'data' => ['user' => new AdminUserResource($user)],
        ], 201);
    }
}
