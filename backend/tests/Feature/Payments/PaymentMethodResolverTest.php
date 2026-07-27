<?php

declare(strict_types=1);

use App\Domain\Payments\PaymentMethodResolver;
use App\Exceptions\Domain\PaymentMethodInactive;
use App\Exceptions\Domain\PaymentMethodUnknown;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;

beforeEach(function (): void {
    $this->location = Location::factory()->create(['code' => 'AAA']);
    $this->group = PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card',
    ]);
    $this->method = PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $this->group->id,
        'code' => 'GCASH', 'name' => 'GCash',
    ]);
});

it('resolves a method to its group\'s driver', function (): void {
    $resolved = app(PaymentMethodResolver::class)->resolve($this->location->id, 'GCASH');

    expect($resolved->id)->toBe($this->method->id);
    expect($resolved->code)->toBe('GCASH');
    expect($resolved->name)->toBe('GCash');
    expect($resolved->groupCode)->toBe('EWALLET');
    // The group carries the driver — this is the whole point of the indirection.
    expect($resolved->driver)->toBe('external_card');
});

it('refuses a code that does not exist at the location', function (): void {
    expect(fn () => app(PaymentMethodResolver::class)->resolve($this->location->id, 'MAYA'))
        ->toThrow(PaymentMethodUnknown::class);
});

it('refuses another location\'s code', function (): void {
    $other = Location::factory()->create(['code' => 'BBB']);

    // Not a bypass and not a 500: the code is simply unknown *here*.
    expect(fn () => app(PaymentMethodResolver::class)->resolve($other->id, 'GCASH'))
        ->toThrow(PaymentMethodUnknown::class);
});

it('refuses an archived method', function (): void {
    $this->method->update(['is_active' => false]);

    expect(fn () => app(PaymentMethodResolver::class)->resolve($this->location->id, 'GCASH'))
        ->toThrow(PaymentMethodInactive::class);
});

it('refuses an active method inside an archived group', function (): void {
    // One switch turns off "we take e-wallets" without touching the methods under it.
    $this->group->update(['is_active' => false]);

    expect(fn () => app(PaymentMethodResolver::class)->resolve($this->location->id, 'GCASH'))
        ->toThrow(PaymentMethodInactive::class);
});

it('reports 422 with distinct error codes', function (): void {
    expect((new PaymentMethodUnknown('loc', 'MAYA'))->errorCode())->toBe('payment_method_unknown');
    expect((new PaymentMethodUnknown('loc', 'MAYA'))->httpStatus())->toBe(422);
    expect((new PaymentMethodInactive('loc', 'GCASH'))->errorCode())->toBe('payment_method_inactive');
    expect((new PaymentMethodInactive('loc', 'GCASH'))->httpStatus())->toBe(422);
});
