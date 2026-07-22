<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Roles;

use App\Domain\Rbac\Permissions;
use App\Http\Requests\Admin\Roles\ListPermissionsRequest;
use Illuminate\Http\JsonResponse;

/**
 * Static data, not an action — the catalog is `Permissions::grouped()` verbatim, nothing
 * to look up or mutate. Backs both the role-editor screen (role.manage) and the
 * user-management screen's per-location role picker (user.manage).
 */
final class ListPermissionsController
{
    public function __invoke(ListPermissionsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'groups' => collect(Permissions::grouped())
                    ->map(fn (array $perms, string $label): array => ['label' => $label, 'permissions' => $perms])
                    ->values(),
            ],
        ]);
    }
}
