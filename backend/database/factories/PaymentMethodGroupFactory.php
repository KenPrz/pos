<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\PaymentMethodGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethodGroup> */
class PaymentMethodGroupFactory extends Factory
{
    protected $model = PaymentMethodGroup::class;

    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'code' => 'CASH',
            'name' => 'Cash',
            'driver' => 'cash',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
