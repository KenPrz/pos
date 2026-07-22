<?php

declare(strict_types=1);

namespace App\Actions\Admin\Roles;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\RoleTemplateInUse;
use App\Exceptions\Domain\RoleTemplateIsSystem;
use App\Models\RoleTemplate;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class DeleteRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function execute(DeleteRoleInput $in): void
    {
        DB::transaction(function () use ($in): void {
            $template = RoleTemplate::query()->lockForUpdate()->findOrFail($in->roleTemplateId);

            if ($template->is_system) {
                throw new RoleTemplateIsSystem($template->id);
            }

            // Lock the materialized `roles` rows themselves, not just the template: the
            // count below and the delete further down must see a consistent world with
            // RoleAssignments::sync()'s insert into model_has_roles, which only holds a
            // plain FK reference to roles.id. In Postgres, a concurrent FK-referencing
            // INSERT takes FOR KEY SHARE on the referenced row, which conflicts with
            // FOR UPDATE — so whichever of "grant" or "delete" arrives first blocks the
            // other until it commits, and the count is never stale. Without this lock,
            // a grant that commits after the count reads 0 but before the delete cascades
            // would have its fresh model_has_roles row silently destroyed by that cascade.
            $roleIds = DB::table('roles')
                ->where('name', $template->name)
                ->whereNotNull('location_id')
                ->lockForUpdate()
                ->pluck('id');

            $assignedUsers = DB::table('model_has_roles')
                ->whereIn('role_id', $roleIds)
                ->count();

            if ($assignedUsers > 0) {
                throw new RoleTemplateInUse($template->id, $assignedUsers);
            }

            // Deleted, not archived: an unassigned custom template has nothing left
            // pointing at it, and role_templates has no is_active column to archive
            // into. Cascades role_has_permissions and role_template_permissions.
            Role::query()->whereIn('id', $roleIds)->delete();

            $template->delete();

            $this->registrar->forgetCachedPermissions();

            $this->audit->record('admin.role.delete', $template, $in->actorId, [
                'name' => $template->name,
            ]);
        });
    }
}
