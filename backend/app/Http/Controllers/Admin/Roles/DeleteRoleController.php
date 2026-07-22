<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Roles;

use App\Actions\Admin\Roles\DeleteRole;
use App\Http\Requests\Admin\Roles\DeleteRoleRequest;
use Illuminate\Http\JsonResponse;

final class DeleteRoleController
{
    public function __invoke(DeleteRoleRequest $request, DeleteRole $action): JsonResponse
    {
        $action->execute($request->toInput());

        return response()->json([
            'data' => ['deleted' => true],
        ]);
    }
}
