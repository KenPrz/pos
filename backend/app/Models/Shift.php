<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A drawer's session: float in, count out, variance recorded. At most one open per
 * register — enforced by a partial unique index, not application code.
 * See docs/02-data-model.md (cash accountability).
 */
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use HasFactory, HasUuids;

    // opened_at/closed_at are the real lifecycle; created_at/updated_at don't exist.
    public $timestamps = false;

    protected $fillable = [
        'register_id', 'opened_by', 'opened_at', 'opening_float_cents',
        'closed_by', 'closed_at', 'counted_cash_cents', 'expected_cash_cents',
        'variance_cents', 'close_note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opening_float_cents' => 'integer',
            'counted_cash_cents' => 'integer',
            'expected_cash_cents' => 'integer',
            'variance_cents' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Register, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
