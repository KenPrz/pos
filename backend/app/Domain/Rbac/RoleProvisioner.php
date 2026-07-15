<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\Location;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the permission catalog and the roles that use it.
 *
 * Shared by the seeder and by location creation, because with teams enabled a role row is
 * **per team**: `Role::create(['name' => 'cashier'])` creates a cashier for one location
 * only. Opening a new store therefore means provisioning its roles, or it is a store
 * nobody can be assigned to. See docs/05-rbac.md.
 *
 * Safe to re-run.
 */
final class RoleProvisioner
{
    public const string GUARD = 'web';

    /**
     * The permission catalog. Permissions are global — only roles are team-scoped.
     *
     * No admin role is created here: admin is `users.is_admin` and bypasses the gate.
     * See docs/05-rbac.md.
     */
    public function provisionGlobal(): void
    {
        foreach (Permissions::all() as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
            ]);
        }

        $this->flushCache();
    }

    /** The per-location roles. Call this for every location, including new ones. */
    public function provisionForLocation(Location $location): void
    {
        foreach (Roles::perLocation() as $name) {
            $role = Role::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
                'location_id' => $location->id,
            ]);

            $role->syncPermissions(Roles::permissionsFor($name));
        }

        $this->flushCache();
    }

    /**
     * The package caches the permission table aggressively. A deploy that seeds new
     * permissions and doesn't flush leaves every terminal denying an ability that exists
     * in the database.
     */
    private function flushCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
