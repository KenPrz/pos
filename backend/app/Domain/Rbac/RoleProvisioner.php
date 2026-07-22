<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\Location;
use App\Models\RoleTemplate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the permission catalog and materializes role templates into per-location roles.
 *
 * Roles are data: a `RoleTemplate` is the runtime definition (name + permission set),
 * global and editable at runtime. With teams enabled, spatie's own `Role` row is
 * **per team** — `Role::create(['name' => 'cashier'])` creates a cashier for one
 * location only — so each template is materialized into its own `Role` row at every
 * location. Opening a new store therefore means provisioning its roles, or it is a
 * store nobody can be assigned to. See docs/05-rbac.md.
 *
 * Shared by the seeder and by location creation. Safe to re-run.
 */
final class RoleProvisioner
{
    public const string GUARD = 'web';

    /**
     * The permission catalog, plus the system role templates, seeded once.
     *
     * Permissions are global — only roles are team-scoped. No admin role/template is
     * created here: admin is `users.is_admin` and bypasses the gate. See docs/05-rbac.md.
     */
    public function provisionGlobal(): void
    {
        foreach (Permissions::all() as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => self::GUARD,
            ]);
        }

        // System templates seed once from the code catalog; after that the TABLE is
        // the runtime truth — an admin edit must never be clobbered by a reseed.
        $seed = [
            Roles::CASHIER => Permissions::cashier(),
            Roles::SUPERVISOR => Permissions::supervisor(),
        ];
        foreach ($seed as $name => $permissions) {
            $template = RoleTemplate::query()->firstOrCreate(['name' => $name], ['is_system' => true]);
            if ($template->wasRecentlyCreated) {
                $ids = Permission::query()->whereIn('name', $permissions)->pluck('id')->all();
                $template->permissions()->sync($ids);
            }
        }

        $this->flushCache();
    }

    /** Materialize every template at this location. Call for every location, including new ones. */
    public function provisionForLocation(Location $location): void
    {
        foreach (RoleTemplate::query()->with('permissions')->get() as $template) {
            $this->materialize($template, $location);
        }
        $this->flushCache();
    }

    /** Push a template's current definition to every location (after create/edit). */
    public function syncTemplate(RoleTemplate $template): void
    {
        $template->loadMissing('permissions');
        foreach (Location::query()->get() as $location) {
            $this->materialize($template, $location);
        }
        $this->flushCache();
    }

    /** After a template rename: rename the materialized per-location rows in place. */
    public function renameMaterialized(string $old, string $new): void
    {
        Role::query()->where('name', $old)->where('guard_name', self::GUARD)
            ->whereNotNull('location_id')->update(['name' => $new]);
        $this->flushCache();
    }

    private function materialize(RoleTemplate $template, Location $location): void
    {
        $role = Role::query()->firstOrCreate([
            'name' => $template->name,
            'guard_name' => self::GUARD,
            'location_id' => $location->id,
        ]);
        $role->syncPermissions($template->permissions->pluck('name')->all());
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
