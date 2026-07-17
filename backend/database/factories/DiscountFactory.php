<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Discount> */
class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'kind' => 'percent',
            'percent_micros' => 100_000,   // 10%, a plausible default
            'amount_cents' => null,
            'scope' => 'order',
            'requires_supervisor' => false,
            'is_active' => true,
        ];
    }

    /** 10% -> percent(100_000). */
    public function percent(int $micros): static
    {
        return $this->state(fn (): array => [
            'kind' => 'percent',
            'percent_micros' => $micros,
            'amount_cents' => null,
        ]);
    }

    public function fixed(int $cents): static
    {
        return $this->state(fn (): array => [
            'kind' => 'fixed',
            'percent_micros' => null,
            'amount_cents' => $cents,
        ]);
    }

    public function line(): static
    {
        return $this->state(fn (): array => ['scope' => 'line']);
    }
}
