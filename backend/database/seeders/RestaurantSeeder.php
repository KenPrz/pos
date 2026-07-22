<?php

declare(strict_types=1);

namespace Database\Seeders;

/** A Manila turo-turo restaurant: open tabs, coursing, rice-and-add-on modifiers. */
class RestaurantSeeder extends CatalogSeeder
{
    protected function locationAttributes(): array
    {
        return [
            'name' => 'Manila Restaurant',
            'code' => 'RST',
            'receipt_header' => 'Manila Restaurant',
            'receipt_footer' => 'Salamat po! VAT included.',
        ];
    }

    protected function registerModes(): array
    {
        return ['Till 1' => 'food', 'Till 2' => 'food'];
    }

    protected function dataFile(): string
    {
        return 'restaurant.json';
    }
}
