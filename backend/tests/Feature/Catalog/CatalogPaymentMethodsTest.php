<?php

declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->headers = staffHeaders($this->register, $this->cashier);
});

it('carries the location\'s active methods in group then method order', function (): void {
    $ewallet = PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card', 'sort_order' => 2,
    ]);
    PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $ewallet->id,
        'code' => 'MAYA', 'name' => 'Maya', 'sort_order' => 1,
    ]);
    PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $ewallet->id,
        'code' => 'GCASH', 'name' => 'GCash', 'sort_order' => 0,
    ]);

    $methods = $this->getJson('/api/v1/catalog', $this->headers)->assertOk()
        ->json('data.payment_methods');

    // CASH (group sort 0), CARD (1), then the e-wallet group's two by their own sort.
    expect(array_column($methods, 'code'))->toBe(['CASH', 'CARD', 'GCASH', 'MAYA']);
    expect($methods[2]['group_code'])->toBe('EWALLET');
    expect($methods[2]['group_name'])->toBe('E-wallets');
    expect($methods[2]['driver'])->toBe('external_card');
});

it('omits archived methods', function (): void {
    PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CARD')->update(['is_active' => false]);

    $methods = $this->getJson('/api/v1/catalog', $this->headers)->assertOk()
        ->json('data.payment_methods');

    expect(array_column($methods, 'code'))->toBe(['CASH']);
});

it('omits every method under an archived group', function (): void {
    // One switch for "we stopped taking cards" — the method rows are untouched.
    PaymentMethodGroup::query()->where('location_id', $this->location->id)
        ->where('code', 'CARD')->update(['is_active' => false]);

    $methods = $this->getJson('/api/v1/catalog', $this->headers)->assertOk()
        ->json('data.payment_methods');

    expect(array_column($methods, 'code'))->toBe(['CASH']);
    expect(PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CARD')->value('is_active'))->toBeTrue();
});

it('never leaks another location\'s methods', function (): void {
    $other = provisionedLocation(['code' => 'ZZZ']);
    $group = PaymentMethodGroup::factory()->create([
        'location_id' => $other->id, 'code' => 'EWALLET', 'driver' => 'external_card',
    ]);
    PaymentMethod::factory()->create([
        'location_id' => $other->id, 'group_id' => $group->id, 'code' => 'GCASH', 'name' => 'GCash',
    ]);

    $methods = $this->getJson('/api/v1/catalog', $this->headers)->assertOk()
        ->json('data.payment_methods');

    expect(array_column($methods, 'code'))->not->toContain('GCASH');
});
