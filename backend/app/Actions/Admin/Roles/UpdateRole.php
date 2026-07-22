<?php

declare(strict_types=1);

namespace App\Actions\Admin\Roles;

use App\Domain\Audit\AuditLogger;
use App\Domain\Rbac\RoleProvisioner;
use App\Exceptions\Domain\RoleTemplateIsSystem;
use App\Models\RoleTemplate;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

final class UpdateRole
{
    public function __construct(
        private readonly RoleProvisioner $provisioner,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(UpdateRoleInput $in): RoleTemplate
    {
        return DB::transaction(function () use ($in): RoleTemplate {
            $template = RoleTemplate::query()->lockForUpdate()->findOrFail($in->roleTemplateId);

            $changed = [];

            if ($in->name !== null && $in->name !== $template->name) {
                if ($template->is_system) {
                    throw new RoleTemplateIsSystem($template->id);
                }

                $old = $template->name;
                $template->name = $in->name;
                $template->save();
                $this->provisioner->renameMaterialized($old, $template->name);
                $changed['name'] = ['from' => $old, 'to' => $template->name];
            }

            if ($in->permissions !== null) {
                $ids = Permission::query()->whereIn('name', $in->permissions)->pluck('id');
                $template->permissions()->sync($ids);
                $this->provisioner->syncTemplate($template);
                $changed['permissions'] = $in->permissions;
            }

            $this->audit->record('admin.role.update', $template, $in->actorId, [
                'changed' => array_keys($changed),
                ...$changed,
            ]);

            return $template->refresh()->load('permissions');
        });
    }
}
