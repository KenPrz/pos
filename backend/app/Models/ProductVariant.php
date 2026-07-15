<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Money;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sellable, stockable SKU ("T-shirt / Blue / L"). Holds the barcode and the price.
 *
 * Every product has at least one, even when trivial — the UI hides a lone "Default".
 * That is the design's most important simplification: the sale path always resolves to a
 * variant, so there is never an "if the product has options" branch at the register.
 * See docs/00-overview.md.
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'barcode',
        'price_cents',
        'cost_cents',
        'tax_rate_id',
        'track_inventory',
        'position',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // bigint -> PHP int. Never float. See docs/01-architecture.md.
            'price_cents' => 'integer',
            'cost_cents' => 'integer',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * The base price. Location overrides are resolved by PriceResolver, not here — a
     * model reaching for request context is how pricing logic ends up in three places.
     */
    public function basePrice(): Money
    {
        return Money::fromCents($this->price_cents);
    }

    /** 'T-shirt — Blue / L', or just 'T-shirt' when the variant is the trivial one. */
    public function displayName(): string
    {
        $product = $this->product->name;

        return $this->name === 'Default' ? $product : "{$product} — {$this->name}";
    }

    /** @param  Builder<ProductVariant>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
