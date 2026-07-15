<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Staff. See docs/02-data-model.md.
 *
 * No `role` attribute: roles come from spatie/laravel-permission and are scoped per
 * location, so "what is this person's role" has no answer without asking "where?".
 * Call sites ask `can('order.void')`, never `role === 'supervisor'`. See docs/05-rbac.md.
 *
 * `HasUuids` generates UUIDv7 in PHP (Str::uuid7), matching the `default uuidv7()` on the
 * column. Both agree; the DB default is the safety net for raw SQL and seeds, while the
 * trait is what lets Eloquent know the id it just wrote.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * spatie matches a permission's guard against the model's. Left to inference it
     * resolves via config('auth.guards'), which with Sanctum in play is a coin flip —
     * and a guard mismatch makes can() return false silently, which for a fraud boundary
     * is the worst failure mode there is. Pinned, and seeded to match.
     */
    protected string $guard_name = 'web';

    protected $fillable = [
        'name',
        'email',
        'password_hash',
        'pin_hash',
        'is_admin',
        'is_active',
    ];

    protected $hidden = [
        'password_hash',
        'pin_hash',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Laravel's auth expects `password`; ours is `password_hash`. */
    public function getAuthPassword(): ?string
    {
        return $this->password_hash;
    }

    /**
     * Which locations this person works at.
     *
     * Derived from role assignments rather than a pivot table: holding a role at a
     * location *is* being assigned there, and a separate pivot would be a second source
     * of truth that could disagree. See docs/05-rbac.md.
     *
     * Queries `model_has_roles` directly rather than via the `roles()` relation, because
     * that relation applies `wherePivot(location_id, currentTeam)` — it can only ever
     * report the location you are already standing at, which is not the question.
     *
     * @return list<string>
     */
    public function locationIds(): array
    {
        return DB::table('model_has_roles')
            ->where('model_id', $this->getKey())
            ->where('model_type', $this->getMorphClass())
            ->distinct()
            ->pluck('location_id')
            ->filter()
            ->values()
            ->all();
    }
}
