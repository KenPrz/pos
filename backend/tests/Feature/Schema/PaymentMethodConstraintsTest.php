<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use Illuminate\Database\QueryException;

it('allows the same code at two different locations', function (): void {
    $a = Location::factory()->create(['code' => 'AAA']);
    $b = Location::factory()->create(['code' => 'BBB']);

    foreach ([$a, $b] as $location) {
        $group = PaymentMethodGroup::factory()->create([
            'location_id' => $location->id, 'code' => 'CASH', 'driver' => 'cash',
        ]);
        PaymentMethod::factory()->create([
            'location_id' => $location->id, 'group_id' => $group->id, 'code' => 'CASH',
        ]);
    }

    expect(PaymentMethod::query()->where('code', 'CASH')->count())->toBe(2);
});

it('refuses two groups sharing a code at one location', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    PaymentMethodGroup::factory()->create(['location_id' => $location->id, 'code' => 'CASH']);

    expect(fn () => PaymentMethodGroup::factory()->create([
        'location_id' => $location->id, 'code' => 'CASH',
    ]))->toThrow(QueryException::class);
});

it('refuses two methods sharing a code at one location', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    $group = PaymentMethodGroup::factory()->create(['location_id' => $location->id, 'code' => 'CASH']);
    PaymentMethod::factory()->create([
        'location_id' => $location->id, 'group_id' => $group->id, 'code' => 'CASH',
    ]);

    expect(fn () => PaymentMethod::factory()->create([
        'location_id' => $location->id, 'group_id' => $group->id, 'code' => 'CASH',
    ]))->toThrow(QueryException::class);
});

it('refuses a method pointing at another location\'s group', function (): void {
    $a = Location::factory()->create(['code' => 'AAA']);
    $b = Location::factory()->create(['code' => 'BBB']);
    $groupAtA = PaymentMethodGroup::factory()->create(['location_id' => $a->id, 'code' => 'CASH']);

    // The composite FK (group_id, location_id) is what rejects this, not a validator.
    expect(fn () => PaymentMethod::factory()->create([
        'location_id' => $b->id, 'group_id' => $groupAtA->id, 'code' => 'CASH',
    ]))->toThrow(QueryException::class);
});

it('refuses a driver outside the registry', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);

    expect(fn () => PaymentMethodGroup::factory()->create([
        'location_id' => $location->id, 'code' => 'CRYPTO', 'driver' => 'bitcoin',
    ]))->toThrow(QueryException::class);
});
