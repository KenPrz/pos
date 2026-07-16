<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tender against an order. amount_cents is immutable once written — corrections are
 * a void plus a new row, never an update. shift_id makes drawer variance computable.
 * See docs/02-data-model.md.
 */
class Payment extends Model
{
    use HasUuids;

    public const null UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'shift_id', 'driver', 'status', 'amount_cents',
        'tendered_cents', 'change_cents', 'reference', 'driver_payload',
        'user_id', 'created_at', 'captured_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'tendered_cents' => 'integer',
            'change_cents' => 'integer',
            'driver_payload' => 'array',
            'created_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
