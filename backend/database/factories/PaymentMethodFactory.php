<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentMethod> */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        // group_id and location_id must agree (composite FK) — callers that care pass
        // both explicitly; this default derives the location from the group it makes.
        $group = PaymentMethodGroup::factory()->create();

        return [
            'location_id' => $group->location_id,
            'group_id' => $group->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
