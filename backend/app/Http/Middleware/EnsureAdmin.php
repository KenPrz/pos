<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\AdminAccessRequired;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin gate: a bearer token whose owner is an active admin USER. Registers also
 * hold sanctum tokens (device tokens), so the instanceof check is load-bearing.
 *
 * Deliberately gates on the user's attributes (`is_admin`, `is_active`), never on the
 * token's abilities. A token ability check would be trivially satisfied here: any
 * `createToken()` call that doesn't pass an ability list defaults to the wildcard
 * `['*']`, so an ability check is not a real second factor — it would only add the
 * appearance of one. Attributes are the enforcement; abilities are just a label.
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_admin || ! $user->is_active) {
            throw new AdminAccessRequired;
        }

        return $next($request);
    }
}
