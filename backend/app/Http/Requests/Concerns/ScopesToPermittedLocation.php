<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Domain\Rbac\AdminAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Refuse a location the caller does not hold the permission at.
 *
 * The back-office login gate (AdminAccess::holdsAnywhere) is deliberately "anywhere" —
 * holding an admin-tier permission at one location is what gets a non-admin into the
 * section at all. It is NOT a blank cheque over every location's data, which is why every
 * location-scoped admin request re-checks the specific location (docs/05-rbac.md). Admins
 * are exempt by definition: locationIdsWhere() returns null (all locations) for them.
 *
 * A `null` locationId — an unknown row id on an update — fails closed, which also avoids
 * leaking whether the row exists.
 */
trait ScopesToPermittedLocation
{
    protected function assertLocationPermitted(?string $locationId, string $permission): void
    {
        $user = $this->user();

        if (! $user instanceof User || $user->is_admin) {
            return;
        }

        $allowed = app(AdminAccess::class)->locationIdsWhere($user, $permission) ?? [];

        if ($locationId === null || ! in_array($locationId, $allowed, true)) {
            throw new AuthorizationException;
        }
    }
}
