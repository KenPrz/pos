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
