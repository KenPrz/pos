<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * New rows, never a mutation of the order they refund. Written from the ACTING
 * register's location/shift/business_date — the register issuing the refund, not
 * necessarily the one that rang the original sale. See docs/02-data-model.md (refunds).
 */
class Refund extends Model
{
    use HasUuids;

    public const null UPDATED_AT = null;

    protected $fillable = [
        'original_order_id', 'location_id', 'register_id', 'shift_id', 'business_date',
        'driver', 'amount_cents', 'reason', 'user_id', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return HasMany<RefundLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RefundLine::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'original_order_id');
    }
}
