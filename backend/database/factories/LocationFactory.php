<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'timezone' => 'America/New_York',
            'prices_include_tax' => false,
            'is_active' => true,
        ];
    }

    /** EU/UK/AU: the shelf price already contains the tax. */
    public function taxInclusive(): static
    {
        return $this->state(fn (): array => [
            'timezone' => 'Europe/London',
            'prices_include_tax' => true,
        ]);
    }
}
