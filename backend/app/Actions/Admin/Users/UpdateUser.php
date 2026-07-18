<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Actions\Auth\SetStaffPin;
use App\Actions\Auth\SetStaffPinInput;
use App\Domain\Audit\AuditLogger;
use App\Domain\Rbac\RoleAssignments;
use App\Exceptions\Domain\EmailOrPinRequired;
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

            // The DB's `users_can_authenticate` CHECK (email is not null or pin_hash is
            // not null) fires at the UPDATE itself — nulling this user's only email with
            // no PIN in play would otherwise surface as a raw constraint-violation 500
            // instead of a graceful refusal. `pin_hash` only ever gets set by
            // SetStaffPin below, never cleared here, so the locked row's current value
            // is the right thing to check against.
            $emailGoingNull = array_key_exists('email', $in->changes) && $in->changes['email'] === null;
            if ($emailGoingNull && $in->pin === null && $user->pin_hash === null) {
                throw new EmailOrPinRequired($in->userId);
            }

            // Roles first: SetStaffPin scopes its collision check to the user's
            // locations, which come from model_has_roles, and a role change in this same
            // request must be in effect before that scope is read.
            $roleChanges = null;
            if ($in->roles !== null) {
                $roleChanges = $this->roles->sync($user, $in->roles);
            }

            // Then the PIN, before the plain-column fill: if this request also nulls the
            // email, `pin_hash` must already be persisted on the row by the time that
            // UPDATE runs, or the CHECK above would be satisfied in memory but violated
            // on disk — the CHECK sees each statement's committed state, not the final
            // one.
            if ($in->pin !== null) {
                $this->setPin->execute(new SetStaffPinInput($user->id, $in->pin, $in->actorId));
            }

            $user->fill($in->changes)->save();

            $payload = ['changed' => array_keys($in->changes)];
            if ($roleChanges !== null && $roleChanges !== []) {
                $payload['roles'] = $roleChanges;
            }

            $this->audit->record('admin.user.update', $user, $in->actorId, $payload);

            return $user->refresh();
        });
    }
}
