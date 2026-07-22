<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Permission;

/**
 * The runtime definition of a role: a name plus a permission set. `RoleProvisioner`
 * materializes each template into a per-location spatie `Role` row and keeps the two
 * in sync. See docs/05-rbac.md.
 */
class RoleTemplate extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'is_system'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_template_permissions');
    }
}
