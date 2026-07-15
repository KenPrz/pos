<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\InvalidDeviceToken;
use App\Models\Register;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the terminal from its device token.
 *
 * The device token alone can read the catalog and list open orders — a terminal showing
 * the menu before anyone clocks in is normal. It cannot touch money; that needs a staff
 * session on top. See docs/01-architecture.md.
 */
final class EnsureDeviceToken
{
    public const string REGISTER = 'pos.register';

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if ($plain === null) {
            throw new InvalidDeviceToken('No device token was presented.');
        }

        $token = PersonalAccessToken::findToken($plain);

        if ($token === null || ! $token->can('device')) {
            throw new InvalidDeviceToken;
        }

        $register = $token->tokenable;

        if (! $register instanceof Register || ! $register->is_active) {
            // Deactivating a register is how a lost or stolen terminal is revoked, so
            // this has to be checked on every request rather than at enrolment.
            throw new InvalidDeviceToken('This register is not active.');
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set(self::REGISTER, $register);

        return $next($request);
    }
}
