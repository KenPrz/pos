<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\TaxRate as TaxRateValue;
use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** See docs/02-data-model.md. */
class TaxRate extends Model
{
    /** @use HasFactory<TaxRateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'rate_micros',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate_micros' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** The value object that knows how to actually apply this rate. */
    public function rate(): TaxRateValue
    {
        return TaxRateValue::fromMicros($this->rate_micros);
    }
}
