<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Users;

use App\Actions\Admin\Users\ListUsers;
use App\Http\Requests\Admin\Users\ListUsersRequest;
use App\Http\Resources\Admin\AdminUserResource;
use Illuminate\Http\JsonResponse;

final class ListUsersController
{
    public function __invoke(ListUsersRequest $request, ListUsers $action): JsonResponse
    {
        return response()->json([
            'data' => ['items' => AdminUserResource::collection($action->execute())],
        ]);
    }
}
