<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ModifierGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order-time choice ("Milk", "Cook temp"). `min_select = 1` makes it required.
 *
 * Modifiers are never stocked. If you need to count it, it's a variant.
 * See docs/00-overview.md.
 */
class ModifierGroup extends Model
{
    /** @use HasFactory<ModifierGroupFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'min_select',
        'max_select',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
        ];
    }

    /** @return HasMany<Modifier, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class, 'group_id')->orderBy('position');
    }

    public function isRequired(): bool
    {
        return $this->min_select >= 1;
    }
}
