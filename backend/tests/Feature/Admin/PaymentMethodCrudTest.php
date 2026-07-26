<?php

declare(strict_types=1);

use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use App\Models\User;

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'AAA']);
    $this->cardGroup = PaymentMethodGroup::query()
        ->where('location_id', $this->location->id)->where('code', 'CARD')->firstOrFail();
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

it('creates, lists, and archives a method', function (): void {
    $created = $this->postJson('/api/v1/admin/payment-methods', [
        'location_id' => $this->location->id,
        'group_id' => $this->cardGroup->id,
        'code' => 'visa', 'name' => 'Visa', 'sort_order' => 1,
    ], $this->headers)->assertCreated();

    expect($created->json('data.payment_method.code'))->toBe('VISA');
    $id = $created->json('data.payment_method.id');

    $this->getJson("/api/v1/admin/payment-methods?location_id={$this->location->id}", $this->headers)
        ->assertOk()
        ->assertJsonFragment(['code' => 'VISA']);

    $this->patchJson("/api/v1/admin/payment-methods/{$id}", ['is_active' => false], $this->headers)
        ->assertOk();
    expect(PaymentMethod::findOrFail($id)->is_active)->toBeFalse();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.payment_method.create', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.payment_method.update', 'entity_id' => $id]);
});

it('derives location from the group and refuses a group at another location', function (): void {
    $other = provisionedLocation(['code' => 'BBB']);
    $groupAtOther = PaymentMethodGroup::query()
        ->where('location_id', $other->id)->where('code', 'CARD')->firstOrFail();

    // The composite FK would reject this at the database; the request refuses it first
    // with a field message rather than a 500.
    $this->postJson('/api/v1/admin/payment-methods', [
        'location_id' => $this->location->id,
        'group_id' => $groupAtOther->id,
        'code' => 'VISA', 'name' => 'Visa',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a duplicate code at one location even across groups', function (): void {
    $cash = PaymentMethodGroup::query()
        ->where('location_id', $this->location->id)->where('code', 'CASH')->firstOrFail();

    // CASH already exists under the cash group; the code is unique per LOCATION.
    $this->postJson('/api/v1/admin/payment-methods', [
        'location_id' => $this->location->id,
        'group_id' => $cash->id,
        'code' => 'CASH', 'name' => 'Cash again',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('accepts the same code at a different location', function (): void {
    $other = provisionedLocation(['code' => 'BBB']);
    $groupAtOther = PaymentMethodGroup::query()
        ->where('location_id', $other->id)->where('code', 'CARD')->firstOrFail();

    foreach ([[$this->location, $this->cardGroup], [$other, $groupAtOther]] as [$location, $group]) {
        $this->postJson('/api/v1/admin/payment-methods', [
            'location_id' => $location->id, 'group_id' => $group->id,
            'code' => 'VISA', 'name' => 'Visa',
        ], $this->headers)->assertCreated();
    }

    expect(PaymentMethod::query()->where('code', 'VISA')->count())->toBe(2);
});

it('ignores code, group and location on update', function (): void {
    $method = PaymentMethod::query()
        ->where('location_id', $this->location->id)->where('code', 'CARD')->firstOrFail();
    $cash = PaymentMethodGroup::query()
        ->where('location_id', $this->location->id)->where('code', 'CASH')->firstOrFail();

    $this->patchJson("/api/v1/admin/payment-methods/{$method->id}", [
        'name' => 'Bank card', 'sort_order' => 5,
        'code' => 'PLASTIC', 'group_id' => $cash->id,
    ], $this->headers)->assertOk();

    $method->refresh();
    expect($method->name)->toBe('Bank card');
    expect($method->sort_order)->toBe(5);
    expect($method->code)->toBe('CARD');
    // Moving a method between groups would change its DRIVER and retroactively re-bucket
    // every historical payment taken on it.
    expect($method->group_id)->toBe($this->cardGroup->id);
});

it('has no delete route', function (): void {
    $method = PaymentMethod::query()->where('location_id', $this->location->id)->firstOrFail();

    $this->deleteJson("/api/v1/admin/payment-methods/{$method->id}", [], $this->headers)
        ->assertStatus(405);
});

it('refuses a session without the permission', function (): void {
    $nobody = User::factory()->create(['email' => 'n@pos.test', 'password_hash' => 'pw']);
    $headers = ['Authorization' => 'Bearer '.$nobody->createToken('t')->plainTextToken];

    $this->getJson("/api/v1/admin/payment-methods?location_id={$this->location->id}", $headers)
        ->assertStatus(403);
});

it('a newly created method is immediately tenderable at the till', function (): void {
    $this->postJson('/api/v1/admin/payment-methods', [
        'location_id' => $this->location->id, 'group_id' => $this->cardGroup->id,
        'code' => 'VISA', 'name' => 'Visa',
    ], $this->headers)->assertCreated();

    $register = registerAt($this->location);
    $cashier = staffWithRole($this->location, \App\Domain\Rbac\Roles::CASHIER);

    $methods = $this->getJson('/api/v1/catalog', staffHeaders($register, $cashier))
        ->assertOk()->json('data.payment_methods');

    expect(array_column($methods, 'code'))->toContain('VISA');
});
