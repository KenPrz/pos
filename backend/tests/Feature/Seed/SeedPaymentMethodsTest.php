<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;

it('gives every seeded location the PH tender set', function (): void {
    config(['pos.seed_catalogs' => 'restaurant']);
    $this->seed(\Database\Seeders\DatabaseSeeder::class);

    $location = Location::query()->where('code', 'RST')->firstOrFail();

    $groups = PaymentMethodGroup::query()->where('location_id', $location->id)
        ->orderBy('sort_order')->pluck('code')->all();
    expect($groups)->toBe(['CASH', 'CARD', 'EWALLET']);

    $methods = PaymentMethod::query()->where('location_id', $location->id)
        ->orderBy('code')->pluck('code')->all();
    expect($methods)->toBe(['CASH', 'GCASH', 'MASTERCARD', 'MAYA', 'VISA']);

    // A multi-group set out of the box, so the till renders real grouping rather than a
    // two-button special case that hides grouping bugs.
    expect(PaymentMethodGroup::query()->where('location_id', $location->id)
        ->where('code', 'EWALLET')->value('driver'))->toBe('external_card');
});
