<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Pins;
use App\Domain\Auth\StaffDirectory;
use App\Exceptions\Domain\PinAlreadyInUse;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Give a member of staff a PIN.
 *
 * The collision check is the whole point. Bcrypt hashes are salted, so two people sharing
 * PIN 1234 produce different hashes and no unique index can catch it — this is the rare
 * invariant that genuinely cannot live in the schema, which is exactly why it needs a
 * dedicated test.
 *
 * It matters because a shared PIN destroys attribution: the audit log would name the
 * wrong person, which in a dispute is worse than having no log at all.
 * See docs/02-data-model.md.
 */
final class SetStaffPin
{
    public function __construct(
        private readonly StaffDirectory $staff,
        private readonly Pins $pins,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(SetStaffPinInput $in): User
    {
        return DB::transaction(function () use ($in): User {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($in->userId);

            // An admin can act anywhere, so their PIN must be unique everywhere.
            $scope = $user->is_admin
                ? Location::query()->pluck('id')->all()
                : $user->locationIds();

            if ($this->staff->pinIsTaken($in->pin, $scope, excludingUserId: $user->id)) {
                throw new PinAlreadyInUse;
            }

            $user->forceFill([
                'pin_hash' => $this->pins->hash($in->pin),
                'pin_lookup' => $this->pins->lookup($in->pin),
            ])->save();

            // The PIN itself is never logged, here or anywhere.
            $this->audit->record('staff.pin.set', $user, $in->actorId);

            return $user;
        });
    }
}
