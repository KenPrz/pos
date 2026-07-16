<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Register;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shift> */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'register_id' => Register::factory(),
            'opened_by' => User::factory(),
            'opened_at' => now(),
            'opening_float_cents' => 20000,
        ];
    }
}
