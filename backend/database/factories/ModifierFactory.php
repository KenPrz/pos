<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Modifier>
 */
class ModifierFactory extends Factory
{
    protected $model = Modifier::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'group_id' => ModifierGroup::factory(),
            'name' => fake()->word(),
            'price_delta_cents' => 0,
            'position' => 0,
            'is_active' => true,
        ];
    }
}
