<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Standard',
            'rate_micros' => 200_000,   // 20%
            'is_active' => true,
        ];
    }

    /** 8.875% — the rate that proves basis points are not enough precision. */
    public function nyc(): static
    {
        return $this->state(fn (): array => [
            'name' => 'NYC Combined',
            'rate_micros' => 88_750,
        ]);
    }

    public function zero(): static
    {
        return $this->state(fn (): array => ['name' => 'Zero', 'rate_micros' => 0]);
    }
}
