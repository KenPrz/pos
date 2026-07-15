<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => 'Default',
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'barcode' => fake()->unique()->numerify('############'),
            'price_cents' => fake()->numberBetween(100, 5000),
            'cost_cents' => null,
            'tax_rate_id' => null,
            'track_inventory' => true,
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function untracked(): static
    {
        return $this->state(fn (): array => ['track_inventory' => false]);
    }
}
