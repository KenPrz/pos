<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing sold, with name/SKU/price/tax-rate frozen at add time so a receipt from
 * last year reprints identically. Never joined to the live catalog for display.
 * Voided, never deleted. See docs/02-data-model.md.
 */
class OrderLine extends Model
{
    use HasUuids;

    public const null UPDATED_AT = null;   // append-then-void; no updated_at column

    protected $fillable = [
        'order_id', 'variant_id', 'name_snapshot', 'sku_snapshot',
        'unit_price_cents', 'tax_rate_micros', 'qty', 'modifiers_total_cents',
        'discount_cents', 'tax_cents', 'line_total_cents', 'position',
        'voided_at', 'voided_by', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'tax_rate_micros' => 'integer',
            'qty' => 'string',              // numeric(12,3) -> string; never float
            'modifiers_total_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'line_total_cents' => 'integer',
            'position' => 'integer',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
