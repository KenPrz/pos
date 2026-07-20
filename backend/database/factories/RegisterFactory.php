<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\Register;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Register>
 */
class RegisterFactory extends Factory
{
    protected $model = Register::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'name' => 'Till '.fake()->unique()->numberBetween(1, 999),
            'is_active' => true,
            // Set explicitly, matching the DB default: create() never hydrates DB column
            // defaults, so a caller reading ->mode straight off a freshly created model
            // would otherwise see null instead of 'retail'. See CLAUDE.md's gotchas.
            'mode' => 'retail',
        ];
    }
}
