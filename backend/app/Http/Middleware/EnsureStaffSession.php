<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\StaffSessionExpired;
use App\Models\Register;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the person acting on the terminal, and sets the permission team context.
 *
 * Runs after EnsureDeviceToken, which is what makes the team context trustworthy: it
 * comes from the *register's* location, never from a request parameter. A client-supplied
 * location would let anyone holding a PIN pick their own permissions.
 * See docs/05-rbac.md.
 */
final class EnsureStaffSession
{
    public const string STAFF_TOKEN = 'pos.staff_token';

    public function __construct(
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Register|null $register */
        $register = $request->attributes->get(EnsureDeviceToken::REGISTER);

        if (! $register instanceof Register) {
            // A programming error, not a client one: the route is misconfigured.
            throw new \LogicException('EnsureStaffSession must run after EnsureDeviceToken.');
        }

        $plain = $request->header('X-Staff-Token');

        if ($plain === null || $plain === '') {
            throw new StaffSessionExpired('No staff session. Please enter your PIN.');
        }

        $token = PersonalAccessToken::findToken($plain);

        if ($token === null) {
            throw new StaffSessionExpired;
        }

        // Sanctum returns expired tokens; the expiry is ours to enforce.
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            throw new StaffSessionExpired;
        }

        // Binds the session to this terminal: a staff token lifted from one register is
        // inert on another.
        if (! $token->can("register:{$register->id}")) {
            throw new StaffSessionExpired('This session belongs to another register.');
        }

        $user = $token->tokenable;

        if (! $user instanceof User || ! $user->is_active) {
            throw new StaffSessionExpired('This account is no longer active.');
        }

        /*
         * Before anything reads a permission, and before anything eager-loads roles.
         * A stale team context does not error — it silently returns the wrong answer,
         * which for a fraud boundary is the worst failure mode available.
         */
        $this->permissions->setPermissionsTeamId($register->location_id);

        $request->setUserResolver(fn (): User => $user);
        $request->attributes->set(self::STAFF_TOKEN, $token);

        return $next($request);
    }
}
