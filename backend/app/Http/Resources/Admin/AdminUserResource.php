<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * `roles` comes from the `role_assignments` attribute the action attaches before
 * wrapping — a direct `model_has_roles` join, never spatie's `roles()` relation. See
 * `App\Domain\Rbac\RoleAssignments`.
 */
final class AdminUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_admin' => (bool) $this->is_admin,
            'is_active' => (bool) $this->is_active,
            'roles' => $this->role_assignments ?? [],
        ];
    }
}
