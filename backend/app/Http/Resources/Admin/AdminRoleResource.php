<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\RoleTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoleTemplate
 *
 * `assigned_users` comes from the attribute `ListRoles` attaches before wrapping — one
 * grouped query over `model_has_roles`, never a per-role N+1. Create/update responses
 * that don't carry the attribute default to 0: the row was just made or just changed and
 * has no assignments to report either way.
 */
final class AdminRoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_system' => (bool) $this->is_system,
            'permissions' => $this->permissions->pluck('name')->sort()->values(),
            'assigned_users' => $this->assigned_users ?? 0,
        ];
    }
}
