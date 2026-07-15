<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auth\Pins;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password_hash' => Hash::make('password'),
            'pin_hash' => null,
            'is_admin' => false,
            'is_active' => true,
        ];
    }

    /** Bypasses the gate entirely — see docs/05-rbac.md. */
    public function admin(): static
    {
        return $this->state(fn (): array => ['is_admin' => true]);
    }

    /**
     * A register-only cashier: a PIN, and no back-office login at all.
     *
     * Sets both representations. pin_hash is the authority; pin_lookup is the index that
     * keeps login to one bcrypt check instead of one per member of staff. Setting only
     * the hash produces a user who cannot log in and no error explaining why.
     */
    public function withPin(string $pin): static
    {
        return $this->state(fn (): array => [
            'email' => null,
            'password_hash' => null,
            'pin_hash' => Hash::make($pin),
            'pin_lookup' => app(Pins::class)->lookup($pin),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
