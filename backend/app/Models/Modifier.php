<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Money;
use Database\Factories\ModifierFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** See docs/02-data-model.md. */
class Modifier extends Model
{
    /** @use HasFactory<ModifierFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'name',
        'price_delta_cents',
        'position',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ModifierGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'group_id');
    }

    /** Signed: 'no cheese, −50c' is a real modifier, and the sign is the meaning. */
    public function priceDelta(): Money
    {
        return Money::fromCents($this->price_delta_cents);
    }
}
