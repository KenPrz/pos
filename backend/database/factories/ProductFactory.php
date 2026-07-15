<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'description' => null,
            'category_id' => null,
            'kind' => 'goods',
            'is_active' => true,
        ];
    }

    /** Services are never stocked. */
    public function service(): static
    {
        return $this->state(fn (): array => ['kind' => 'service']);
    }
}
