<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin-named tender at one location. `code` and `group_id` are immutable after
 * create: the group carries the driver, so moving a method between groups would change
 * its behaviour, and the code is a wire identifier and a report key.
 * See docs/02-data-model.md.
 */
class PaymentMethod extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['location_id', 'group_id', 'code', 'name', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<PaymentMethodGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PaymentMethodGroup::class, 'group_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
