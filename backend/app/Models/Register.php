<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RegisterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

/**
 * A terminal — a physical station, not a person. See docs/02-data-model.md.
 *
 * The register *is* the device token's owner (HasApiTokens), which is what makes
 * per-location RBAC free: the token identifies the register, the register knows its
 * location, so the permission team context is a property of the hardware the request
 * came from and is never client-supplied. See docs/05-rbac.md.
 */
class Register extends Model
{
    /** @use HasFactory<RegisterFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    protected $fillable = [
        'location_id',
        'name',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
