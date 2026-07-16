<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One original order line's share of a refund. amount_cents is derived server-side from
 * the line's frozen price/tax snapshot — never client-sent. See docs/02-data-model.md.
 */
class RefundLine extends Model
{
    use HasUuids;

    public $timestamps = false;   // no created_at/updated_at column at all

    protected $fillable = [
        'refund_id', 'original_order_line_id', 'qty', 'amount_cents', 'restock',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qty' => 'string',   // numeric(12,3) -> string; never float
            'amount_cents' => 'integer',
            'restock' => 'boolean',
        ];
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return BelongsTo<OrderLine, $this> */
    public function originalOrderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'original_order_line_id');
    }
}
