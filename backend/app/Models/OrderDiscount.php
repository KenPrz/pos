<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One discount applied to an order, or to a single line within it (`order_line_id`
 * null = order-level). `amount_cents` is the *resolved* figure, not the percentage —
 * DiscountResolver rewrites it every recalculate, so a 10% discount on an order that
 * later gains a line does not silently keep a stale amount; it is recalculated and
 * rewritten explicitly, by code that is tested. See docs/02-data-model.md.
 */
class OrderDiscount extends Model
{
    use HasUuids;

    public const null UPDATED_AT = null;   // append-then-rewrite; no updated_at column

    protected $fillable = [
        'order_id', 'order_line_id', 'discount_id', 'name_snapshot',
        'amount_cents', 'applied_by', 'reason', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Discount, $this> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
