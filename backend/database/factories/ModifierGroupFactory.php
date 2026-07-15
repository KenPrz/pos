<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    protected $model = ModifierGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'min_select' => 0,
            'max_select' => null,
        ];
    }

    /** min_select = 1 makes the group required ('choose a cook temp'). */
    public function required(): static
    {
        return $this->state(fn (): array => ['min_select' => 1, 'max_select' => 1]);
    }
}
