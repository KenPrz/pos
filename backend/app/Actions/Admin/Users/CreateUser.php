<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Actions\Auth\SetStaffPin;
use App\Actions\Auth\SetStaffPinInput;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Pins;
use App\Domain\Rbac\PermissionAssignments;
use App\Domain\Rbac\RoleAssignments;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    public function __construct(
        private readonly SetStaffPin $setPin,
        private readonly RoleAssignments $roles,
        private readonly PermissionAssignments $permissions,
        private readonly AuditLogger $audit,
        private readonly Pins $pins,
    ) {}

    public function execute(CreateUserInput $in): User
    {
        return DB::transaction(function () use ($in): User {
            $user = User::create([
                'name' => $in->name,
                'email' => $in->email,
                'password_hash' => $in->password,   // 'hashed' cast bcrypts; null stays null
                // The `users_can_authenticate` CHECK (email is not null or pin_hash is not
                // null) is evaluated at this INSERT — Postgres CHECK constraints aren't
                // deferrable — so a PIN-only row needs a real pin_hash right away, not
                // after SetStaffPin runs. SetStaffPin below re-derives and re-saves the
                // canonical hash/lookup anyway (its collision check excludes this same
                // user), so this is only what the schema requires at row-creation time,
                // not a second source of truth.
                'pin_hash' => $in->pin !== null ? $this->pins->hash($in->pin) : null,
                'is_admin' => $in->isAdmin,
                'is_active' => true,
            ]);

            $this->roles->sync($user, $in->roles);
            $this->permissions->sync($user, $in->permissions);

            if ($in->pin !== null) {
                // Roles must exist first: SetStaffPin scopes its collision check to the
                // user's locations, which come from model_has_roles.
                $this->setPin->execute(new SetStaffPinInput($user->id, $in->pin, $in->actorId));
            }

            $payload = [
                'name' => $in->name, 'is_admin' => $in->isAdmin,
                'roles' => $in->roles,
            ];
            if ($in->permissions !== []) {
                $payload['permissions'] = $in->permissions;
            }

            $this->audit->record('admin.user.create', $user, $in->actorId, $payload);

            return $user->refresh();
        });
    }
}
