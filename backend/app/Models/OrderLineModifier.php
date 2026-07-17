<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Money;
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    /** Signed: 'no cheese, −50c' is a real modifier, and the sign is the meaning. */
    public function priceDelta(): Money
    {
        return Money::fromCents($this->price_delta_cents);
    }
}
