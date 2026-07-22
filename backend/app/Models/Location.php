<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical store or venue. Owns stock, registers, and — via spatie teams — roles.
 * See docs/02-data-model.md.
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'code',
        'timezone',
        'prices_include_tax',
        'address',
        'receipt_header',
        'receipt_footer',
        'is_active',
        'variance_approval_threshold_cents',
        'low_stock_threshold',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'prices_include_tax' => 'boolean',
            'is_active' => 'boolean',
            'address' => 'array',
            'variance_approval_threshold_cents' => 'integer',
            'low_stock_threshold' => 'string', // numeric(12,3) -> string; never float
        ];
    }

    /** @return HasMany<Register, $this> */
    public function registers(): HasMany
    {
        return $this->hasMany(Register::class);
    }
}
