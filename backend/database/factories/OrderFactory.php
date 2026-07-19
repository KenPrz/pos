<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\Order;
use App\Models\Register;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'TT-'.fake()->unique()->numerify('########'),
            'location_id' => Location::factory(),
            'register_id' => Register::factory(),
            'shift_id' => Shift::factory(),
            'business_date' => now()->toDateString(),
            'opened_by' => User::factory(),
            'status' => 'open',
            'prices_include_tax' => false,
            'opened_at' => now(),
        ];
    }

    /** Wire location/register/shift into one consistent chain. */
    public function forRegister(Register $register): static
    {
        return $this->state(function () use ($register): array {
            return [
                'location_id' => $register->location_id,
                'register_id' => $register->id,
                'shift_id' => Shift::where('register_id', $register->id)->whereNull('closed_at')->value('id')
                    ?? Shift::factory()->create(['register_id' => $register->id])->id,
                // Mirrors OpenOrder's own business_date computation (the location's
                // timezone, never app-default UTC) — a factory-made order's business_date
                // must agree with whatever the real actions (OpenOrder, RefundOrder)
                // compute from the same location, or ledger-basis reports comparing the
                // two mismatch for a few hours a day whenever the location's timezone
                // trails UTC across midnight. `Location::find()`, not `$register->location`
                // — that relation isn't eager-loaded here, and
                // `Model::preventLazyLoading()` is on outside production.
                'business_date' => now(Location::find($register->location_id)?->timezone ?? config('app.timezone'))->toDateString(),
            ];
        });
    }
}
