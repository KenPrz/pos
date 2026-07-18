<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The lifecycle both retail and food service travel, at different speeds.
 * Totals are always server-computed by OrderTotals; `version` is the optimistic lock.
 * See docs/02-data-model.md and docs/03-api.md.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;   // opened_at/closed_at are the lifecycle

    protected $fillable = [
        'number', 'location_id', 'register_id', 'shift_id', 'business_date',
        'opened_by', 'closed_by', 'customer_id', 'table_ref', 'status',
        'prices_include_tax', 'subtotal_cents', 'discount_cents', 'tax_cents',
        'total_cents', 'paid_cents', 'version', 'opened_at', 'closed_at',
        'voided_at', 'void_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'prices_include_tax' => 'boolean',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_cents' => 'integer',
            'version' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'voided_at' => 'datetime',
            // business_date stays the raw 'YYYY-MM-DD' string Postgres returns — it is
            // a local calendar day, and a datetime cast would re-attach a timezone.
        ];
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<OrderDiscount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Register, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
