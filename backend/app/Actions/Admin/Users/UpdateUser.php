<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Actions\Auth\SetStaffPin;
use App\Actions\Auth\SetStaffPinInput;
use App\Domain\Audit\AuditLogger;
use App\Domain\Rbac\RoleAssignments;
use App\Exceptions\Domain\SelfLockout;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateUser
{
    public function __construct(
        private readonly SetStaffPin $setPin,
        private readonly RoleAssignments $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(UpdateUserInput $in): User
    {
        // The acting admin cannot set their own is_admin => false or is_active => false.
        // Checked before the transaction opens — a self-lockout is never partially
        // applied.
        if ($in->userId === $in->actorId
            && (($in->changes['is_admin'] ?? true) === false || ($in->changes['is_active'] ?? true) === false)) {
            throw new SelfLockout($in->userId);
        }

        return DB::transaction(function () use ($in): User {
            $user = User::query()->lockForUpdate()->findOrFail($in->userId);

            $user->fill($in->changes)->save();

            $roleChanges = null;
            if ($in->roles !== null) {
                $roleChanges = $this->roles->sync($user, $in->roles);
            }

            if ($in->pin !== null) {
                $this->setPin->execute(new SetStaffPinInput($user->id, $in->pin, $in->actorId));
            }

            $payload = ['changed' => array_keys($in->changes)];
            if ($roleChanges !== null && $roleChanges !== []) {
                $payload['roles'] = $roleChanges;
            }

            $this->audit->record('admin.user.update', $user, $in->actorId, $payload);

            return $user->refresh();
        });
    }
}
