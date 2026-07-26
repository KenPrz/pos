<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The behavioural bucket: one driver, per location. `code` and `driver` are immutable
 * after create — changing either would silently change how every method under it
 * behaves and retroactively re-bucket history. See docs/02-data-model.md.
 */
class PaymentMethodGroup extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['location_id', 'code', 'name', 'driver', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<PaymentMethod, $this> */
    public function methods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'group_id');
    }
}
