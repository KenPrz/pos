<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\StaffDirectory;
use App\Exceptions\Domain\InvalidPin;
use App\Exceptions\Domain\TooManyPinAttempts;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * A cashier taps a PIN on an already-enrolled terminal and gets a short-lived session.
 *
 * The PIN is a weak secret and is only acceptable because of the two constraints around
 * it: it arrives from a device an admin enrolled, and it is rate-limited per register.
 * See docs/01-architecture.md.
 */
final class StaffLogin
{
    public function __construct(
        private readonly StaffDirectory $staff,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function execute(StaffLoginInput $in): StaffSession
    {
        $key = $this->throttleKey($in->register->id);
        $maxAttempts = (int) config('pos.staff.pin_max_attempts');

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            throw new TooManyPinAttempts($this->limiter->availableIn($key));
        }

        $user = $this->staff->findByPin($in->register->location_id, $in->pin);

        if ($user === null) {
            // Counted per register, not per user: we do not know who was trying, and a
            // per-user counter would let an attacker lock a cashier out at will.
            $this->limiter->hit($key, (int) config('pos.staff.pin_lockout_seconds'));

            $this->audit->record('staff.login.failed', 'Register', null, [], $in->register->id);

            throw new InvalidPin;
        }

        $this->limiter->clear($key);

        /*
         * Set the team context here, not just in EnsureStaffSession.
         *
         * Login runs *before* a staff session exists, so that middleware hasn't run — and
         * without this, reading the user's permissions for the response returns an empty
         * list. Not an error: silently empty, which is exactly the failure mode
         * docs/05-rbac.md warns about, and it is why the login response's permission list
         * is asserted in a test rather than eyeballed.
         */
        $this->permissions->setPermissionsTeamId($in->register->location_id);
        $user->unsetRelation('roles');

        $expiresAt = Carbon::now()->addMinutes((int) config('pos.staff.session_ttl_minutes'));

        // The ability binds the session to this register. A staff token lifted from one
        // terminal is inert on another, so a session cannot walk across the shop floor.
        $token = $user->createToken(
            "staff:{$in->register->id}",
            ["register:{$in->register->id}"],
            $expiresAt,
        );

        $this->audit->record('staff.login', $user, $user->id, [], $in->register->id);

        return new StaffSession($user, $token->plainTextToken, $expiresAt);
    }

    private function throttleKey(string $registerId): string
    {
        return "pin:{$registerId}";
    }
}
