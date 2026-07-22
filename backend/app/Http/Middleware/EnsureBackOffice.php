<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Rbac\AdminAccess;
use App\Exceptions\Domain\AdminAccessRequired;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The back-office gate: a bearer token whose owner is an active user holding at least
 * one admin-tier permission (or `is_admin`, which bypasses entirely). Registers also
 * hold sanctum tokens (device tokens), so the instanceof check is load-bearing.
 *
 * Deliberately gates on the user's attributes and permission grants, never on the
 * token's abilities. A token ability check would be trivially satisfied here: any
 * `createToken()` call that doesn't pass an ability list defaults to the wildcard
 * `['*']`, so an ability check is not a real second factor — it would only add the
 * appearance of one. Attributes (and `AdminAccess`'s direct-join resolution) are the
 * enforcement; abilities are just a label.
 */
final class EnsureBackOffice
{
    public function __construct(private readonly AdminAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active || ! $this->access->holdsAnyAdminSection($user)) {
            throw new AdminAccessRequired;
        }

        return $next($request);
    }
}
