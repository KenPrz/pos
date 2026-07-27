<?php

declare(strict_types=1);

use App\Domain\Rbac\Permissions;
use App\Models\Location;
use App\Models\PaymentMethodGroup;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'AAA']);
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

it('creates, lists, and archives a group', function (): void {
    $created = $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $this->location->id,
        'code' => 'ewallet', 'name' => 'E-wallets', 'driver' => 'external_card', 'sort_order' => 2,
    ], $this->headers)->assertCreated();

    // Codes are normalized to uppercase — they are wire identifiers and report keys.
    expect($created->json('data.payment_method_group.code'))->toBe('EWALLET');
    $id = $created->json('data.payment_method_group.id');

    $this->getJson("/api/v1/admin/payment-method-groups?location_id={$this->location->id}", $this->headers)
        ->assertOk()
        ->assertJsonPath('data.items.2.code', 'EWALLET');   // after the CASH/CARD defaults

    $this->patchJson("/api/v1/admin/payment-method-groups/{$id}", ['is_active' => false], $this->headers)
        ->assertOk();
    expect(PaymentMethodGroup::findOrFail($id)->is_active)->toBeFalse();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.payment_method_group.create', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.payment_method_group.update', 'entity_id' => $id]);
});

it('refuses a duplicate code at one location', function (): void {
    // A validation failure (Rule::unique) — this codebase's envelope maps
    // ValidationException to 400 validation_failed, never Laravel's default 422
    // (ApiErrorEnvelope.php:39-43). 422 is reserved for semantically-rejected-but-
    // well-formed requests, which this is not.
    $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $this->location->id,
        'code' => 'CASH', 'name' => 'Cash again', 'driver' => 'cash',
    ], $this->headers)->assertStatus(400);
});

it('accepts the same code at a different location', function (): void {
    $other = provisionedLocation(['code' => 'BBB']);

    $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $other->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card',
    ], $this->headers)->assertCreated();
    $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card',
    ], $this->headers)->assertCreated();

    expect(PaymentMethodGroup::query()->where('code', 'EWALLET')->count())->toBe(2);
});

it('refuses a driver outside the registry', function (): void {
    // Same reasoning as above: an `in:` rule failure is a validation failure -> 400.
    $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $this->location->id,
        'code' => 'CRYPTO', 'name' => 'Crypto', 'driver' => 'bitcoin',
    ], $this->headers)->assertStatus(400);
});

it('ignores code, driver and location on update', function (): void {
    $group = PaymentMethodGroup::query()->where('location_id', $this->location->id)
        ->where('code', 'CARD')->firstOrFail();
    $other = provisionedLocation(['code' => 'BBB']);

    $this->patchJson("/api/v1/admin/payment-method-groups/{$group->id}", [
        'name' => 'Bank cards',
        'code' => 'PLASTIC', 'driver' => 'cash', 'location_id' => $other->id,
    ], $this->headers)->assertOk();

    $group->refresh();
    // The name is display copy; the code is a report key and the driver IS the behaviour.
    expect($group->name)->toBe('Bank cards');
    expect($group->code)->toBe('CARD');
    expect($group->driver)->toBe('external_card');
    expect($group->location_id)->toBe($this->location->id);
});

it('has no delete route', function (): void {
    $group = PaymentMethodGroup::query()->where('location_id', $this->location->id)->firstOrFail();

    $this->deleteJson("/api/v1/admin/payment-method-groups/{$group->id}", [], $this->headers)
        ->assertStatus(405);
});

it('refuses a session without the permission', function (): void {
    $nobody = User::factory()->create(['email' => 'n@pos.test', 'password_hash' => 'pw']);
    $headers = ['Authorization' => 'Bearer '.$nobody->createToken('t')->plainTextToken];

    $this->getJson("/api/v1/admin/payment-method-groups?location_id={$this->location->id}", $headers)
        ->assertStatus(403);
});

it('scopes a non-admin holder to the locations they hold it at', function (): void {
    $other = provisionedLocation(['code' => 'BBB']);
    $manager = User::factory()->create(['email' => 'm@pos.test', 'password_hash' => 'pw']);

    // Direct grant at ONE location — holding it somewhere gets you into the section, not
    // into every store's tenders (docs/05-rbac.md).
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->location->id);
    $manager->givePermissionTo(Permissions::PAYMENT_METHOD_MANAGE);
    $registrar->forgetCachedPermissions();

    $headers = ['Authorization' => 'Bearer '.$manager->createToken('t')->plainTextToken];

    $this->getJson("/api/v1/admin/payment-method-groups?location_id={$this->location->id}", $headers)
        ->assertOk();
    $this->getJson("/api/v1/admin/payment-method-groups?location_id={$other->id}", $headers)
        ->assertStatus(403);

    // The two PATCH/POST routes take a DIFFERENT path than the list above: PATCH sources
    // the location from the row (not the request body), and POST names it directly. Both
    // must refuse the same manager against $other's data, not just the list endpoint.
    $groupAtOther = PaymentMethodGroup::query()->where('location_id', $other->id)->firstOrFail();
    $this->patchJson("/api/v1/admin/payment-method-groups/{$groupAtOther->id}", ['name' => 'Nope'], $headers)
        ->assertStatus(403);

    $this->postJson('/api/v1/admin/payment-method-groups', [
        'location_id' => $other->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card',
    ], $headers)->assertStatus(403);
});
