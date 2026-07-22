<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Domain\Rbac\AdminAccess;
use App\Models\User;

trait AuthorizesBackOffice
{
    protected function allowsBackOffice(string $permission): bool
    {
        $user = $this->user();

        return $user instanceof User
            && ($user->is_admin || app(AdminAccess::class)->holdsAnywhere($user, $permission));
    }
}
