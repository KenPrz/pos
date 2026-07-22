<?php

declare(strict_types=1);

namespace Database\Seeders;

/** A Manila neighborhood grocery: barcoded shelf goods, everything tracked. */
class GrocerySeeder extends CatalogSeeder
{
    protected function locationAttributes(): array
    {
        return [
            'name' => 'Manila Grocery',
            'code' => 'GRC',
            'receipt_header' => 'Manila Grocery',
            'receipt_footer' => 'Salamat po! VAT included.',
        ];
    }

    protected function registerModes(): array
    {
        return ['Till 1' => 'retail', 'Till 2' => 'retail'];
    }

    protected function dataFile(): string
    {
        return 'grocery.json';
    }
}
