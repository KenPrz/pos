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
 * one admin-tier permission (or `is_admin`, which bypasses entirely), presented on a
 * token that was actually issued by `POST /admin/login`. Registers also hold sanctum
 * tokens (device tokens), so the instanceof check is load-bearing.
 *
 * Both halves matter now, for different reasons. Now that ordinary role permissions
 * (e.g. a supervisor's default `report.sales.view`/`report.stock.view`) can open
 * admin-tier sections, the attribute/permission check alone is not sufficient: a
 * register staff-session token (`StaffLogin`'s `register:{id}` ability) authenticates
 * as that same user and would otherwise pass straight through. The ability check pins
 * access to tokens actually minted by admin login — staff tokens carry `register:{id}`,
 * device tokens carry `['device']`, neither satisfies `can('admin')`. `AdminLogin`
 * issues `['admin']`, and test helpers' bare `createToken('t')` default to the
 * wildcard `['*']`, which still passes. The attribute/permission check still matters
 * too: it is what scopes an admin-login token to the sections its holder actually
 * holds, not just proof of where the token came from.
 */
final class EnsureBackOffice
{
    public function __construct(private readonly AdminAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || ! $user->is_active
            || ! $this->access->holdsAnyAdminSection($user)
            || ! $user->currentAccessToken()?->can('admin')
        ) {
            throw new AdminAccessRequired;
        }

        return $next($request);
    }
}
