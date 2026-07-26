<?php

declare(strict_types=1);

use App\Domain\Payments\PaymentMethodProvisioner;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use App\Models\User;

it('writes the default cash and card set', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);

    app(PaymentMethodProvisioner::class)->provisionForLocation($location->id);

    $groups = PaymentMethodGroup::query()->where('location_id', $location->id)
        ->orderBy('sort_order')->get();
    expect($groups->pluck('code')->all())->toBe(['CASH', 'CARD']);
    expect($groups->pluck('driver')->all())->toBe(['cash', 'external_card']);

    $methods = PaymentMethod::query()->where('location_id', $location->id)
        ->orderBy('code')->get();
    expect($methods->pluck('code')->all())->toBe(['CARD', 'CASH']);
});

it('is idempotent — a second call adds nothing', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    $provisioner = app(PaymentMethodProvisioner::class);

    $provisioner->provisionForLocation($location->id);
    $provisioner->provisionForLocation($location->id);

    expect(PaymentMethodGroup::query()->where('location_id', $location->id)->count())->toBe(2);
    expect(PaymentMethod::query()->where('location_id', $location->id)->count())->toBe(2);
});

it('leaves an admin-renamed default alone on a re-run', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    $provisioner = app(PaymentMethodProvisioner::class);
    $provisioner->provisionForLocation($location->id);

    PaymentMethodGroup::query()->where('location_id', $location->id)
        ->where('code', 'CASH')->update(['name' => 'Cash (peso)']);

    $provisioner->provisionForLocation($location->id);

    expect(PaymentMethodGroup::query()->where('location_id', $location->id)
        ->where('code', 'CASH')->value('name'))->toBe('Cash (peso)');
});

it('provisions a location created through the back office', function (): void {
    $admin = User::factory()->create([
        'email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true,
    ]);
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    $created = $this->postJson('/api/v1/admin/locations', [
        'name' => 'Makati', 'code' => 'MKT', 'timezone' => 'Asia/Manila',
    ], $headers)->assertCreated();

    // RBAC v2 fixed exactly this bug for roles; a location with no tenders is the same
    // bug with a different noun — every payment at it would 422.
    expect(PaymentMethod::query()
        ->where('location_id', $created->json('data.location.id'))->count())->toBe(2);
});
