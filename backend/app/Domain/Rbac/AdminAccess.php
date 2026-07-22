<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Holds it anywhere" resolution for the back office. Admin-tier surfaces are
 * global, so access is granted when the user holds the permission at ANY location,
 * via a role or a direct grant. Direct table joins on purpose — spatie's relations
 * scope to the current team and answer the wrong question silently (CLAUDE.md).
 */
final class AdminAccess
{
    public const array SECTIONS = [
        Permissions::CATALOG_MANAGE, Permissions::USER_MANAGE, Permissions::LOCATION_MANAGE,
        Permissions::REGISTER_ENROLL, Permissions::AUDIT_VIEW, Permissions::REPORT_SALES_VIEW,
        Permissions::REPORT_STOCK_VIEW, Permissions::SETTINGS_MANAGE, Permissions::ROLE_MANAGE,
    ];

    public function holdsAnywhere(User $user, string $permission): bool
    {
        return $user->is_admin || in_array($permission, $this->allHeld($user), true);
    }

    /** @return array<string> every permission held at any location, role-derived or direct */
    public function allHeld(User $user): array
    {
        $viaRoles = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->pluck('permissions.name');

        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', $user->getMorphClass())
            ->where('model_has_permissions.model_id', $user->getKey())
            ->pluck('permissions.name');

        return $viaRoles->merge($direct)->unique()->values()->all();
    }

    /** @return array<string> the admin-tier sections this user may see, in canonical order */
    public function sectionsFor(User $user): array
    {
        if ($user->is_admin) {
            return self::SECTIONS;
        }

        return array_values(array_intersect(self::SECTIONS, $this->allHeld($user)));
    }

    public function holdsAnyAdminSection(User $user): bool
    {
        return $user->is_admin || $this->sectionsFor($user) !== [];
    }

    /** @return array<string>|null location ids where the permission is held; null = all (admin) */
    public function locationIdsWhere(User $user, string $permission): ?array
    {
        if ($user->is_admin) {
            return null;
        }

        $viaRoles = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('permissions.name', $permission)
            ->pluck('model_has_roles.location_id');

        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', $user->getMorphClass())
            ->where('model_has_permissions.model_id', $user->getKey())
            ->where('permissions.name', $permission)
            ->pluck('model_has_permissions.location_id');

        return $viaRoles->merge($direct)->unique()->values()->all();
    }
}
