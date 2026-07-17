<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A modifier frozen onto a line at add time — name and per-unit delta are snapshots,
 * never joined back to the live catalog (receipts must reprint identically forever).
 */
final class OrderLineModifier extends Model
{
    use HasUuids;

    protected $table = 'order_line_modifiers';

    public $timestamps = false;

    protected $fillable = ['order_line_id', 'modifier_id', 'name_snapshot', 'price_delta_cents'];

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }
}
