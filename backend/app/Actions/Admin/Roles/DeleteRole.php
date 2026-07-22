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

            $assignedUsers = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', $template->name)
                ->whereNotNull('roles.location_id')
                ->count();

            if ($assignedUsers > 0) {
                throw new RoleTemplateInUse($template->id, $assignedUsers);
            }

            // Deleted, not archived: an unassigned custom template has nothing left
            // pointing at it, and role_templates has no is_active column to archive
            // into. Cascades role_has_permissions and role_template_permissions.
            Role::query()->where('name', $template->name)->whereNotNull('location_id')->delete();

            $template->delete();

            $this->registrar->forgetCachedPermissions();

            $this->audit->record('admin.role.delete', $template, $in->actorId, [
                'name' => $template->name,
            ]);
        });
    }
}
