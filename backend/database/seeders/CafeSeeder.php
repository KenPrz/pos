<?php

declare(strict_types=1);

namespace Database\Seeders;

/** A Manila cafe: espresso drinks with milk/size/extras modifiers, counter pastries. */
class CafeSeeder extends CatalogSeeder
{
    protected function locationAttributes(): array
    {
        return [
            'name' => 'Manila Cafe',
            'code' => 'CAF',
            'receipt_header' => 'Manila Cafe',
            'receipt_footer' => 'Salamat po! VAT included.',
        ];
    }

    protected function registerModes(): array
    {
        return ['Till 1' => 'food', 'Till 2' => 'food'];
    }

    protected function dataFile(): string
    {
        return 'cafe.json';
    }
}
