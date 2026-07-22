<?php

declare(strict_types=1);

namespace App\Actions\Admin\Roles;

use App\Domain\Audit\AuditLogger;
use App\Domain\Rbac\RoleProvisioner;
use App\Models\RoleTemplate;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

final class CreateRole
{
    public function __construct(
        private readonly RoleProvisioner $provisioner,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CreateRoleInput $in): RoleTemplate
    {
        return DB::transaction(function () use ($in): RoleTemplate {
            $template = RoleTemplate::create([
                'name' => $in->name,
                'is_system' => false,
            ]);

            $ids = Permission::query()->whereIn('name', $in->permissions)->pluck('id');
            $template->permissions()->sync($ids);

            // Materialize immediately — a template that isn't provisioned at every
            // location yet is one no user can be assigned to.
            $this->provisioner->syncTemplate($template);

            $this->audit->record('admin.role.create', $template, $in->actorId, [
                'name' => $in->name, 'permissions' => $in->permissions,
            ]);

            return $template->refresh()->load('permissions');
        });
    }
}
