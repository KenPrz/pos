<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Discount as DiscountValueObject;
use App\Domain\Money\DiscountKind;
use App\Domain\Money\Money;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable discount definition ("10% off", "$5 off"). Thin on purpose: all the
 * resolution math lives in App\Domain\Money\Discount (the value object) and
 * DiscountResolver. toValueObject() is the only bridge between the row and that math.
 */
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'kind', 'percent_micros', 'amount_cents', 'scope',
        'requires_supervisor', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'percent_micros' => 'integer',
            'amount_cents' => 'integer',
            'requires_supervisor' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function toValueObject(): DiscountValueObject
    {
        return match ($this->kind) {
            DiscountKind::Percent => DiscountValueObject::percent($this->percent_micros),
            DiscountKind::Fixed => DiscountValueObject::fixed(Money::fromCents($this->amount_cents)),
        };
    }
}
