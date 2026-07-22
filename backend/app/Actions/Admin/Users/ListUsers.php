<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Domain\Rbac\PermissionAssignments;
use App\Domain\Rbac\RoleAssignments;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ListUsers
{
    public function __construct(
        private readonly RoleAssignments $roles,
        private readonly PermissionAssignments $permissions,
    ) {}

    /** @return Collection<int, User> */
    public function execute(): Collection
    {
        $users = User::query()->orderBy('name')->get();

        $rolesByUser = $this->roles->describeMany($users);
        $permissionsByUser = $this->permissions->describeMany($users);

        return $users->each(function (User $user) use ($rolesByUser, $permissionsByUser): void {
            $user->setAttribute('role_assignments', $rolesByUser->get($user->id, []));
            $user->setAttribute('permission_assignments', $permissionsByUser->get($user->id, []));
        });
    }
}
