<?php

// backend/tests/Feature/Admin/UserManagementTest.php
declare(strict_types=1);

use App\Models\Location;
use App\Models\Register;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['email' => 'boss@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken];
    $this->location = provisionedLocation(['code' => 'DT']);
});

function deviceHeaders(Register $register): array
{
    return ['Authorization' => 'Bearer '.$register->createToken('device', ['device'])->plainTextToken];
}

it('creates a PIN-only cashier with a role at one location, and they can PIN-login there', function (): void {
    $userId = $this->postJson('/api/v1/admin/users', [
        'name' => 'Cora Cashier',
        'pin' => '4321',
        'roles' => [['location_id' => $this->location->id, 'role' => 'cashier']],
    ], $this->headers)->assertCreated()->json('data.user.id');

    $this->assertDatabaseHas('model_has_roles', [
        'model_id' => $userId,
        'location_id' => $this->location->id,
    ]);

    $register = registerAt($this->location);
    $login = $this->postJson('/api/v1/staff/login', ['pin' => '4321'], deviceHeaders($register))
        ->assertOk();
    expect($login->json('data.user.id'))->toBe($userId);

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.user.create', 'entity_id' => $userId]);
});

it('creates an email-only admin', function (): void {
    $response = $this->postJson('/api/v1/admin/users', [
        'name' => 'Ada Admin',
        'email' => 'ada@pos.test',
        'is_admin' => true,
    ], $this->headers)->assertCreated();

    expect($response->json('data.user.is_admin'))->toBeTrue();
    expect(User::where('email', 'ada@pos.test')->exists())->toBeTrue();
});

it('rejects a user with neither email nor pin', function (): void {
    $this->postJson('/api/v1/admin/users', ['name' => 'Nobody'], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('replaces roles on update — cashier becomes supervisor at the same location', function (): void {
    $userId = $this->postJson('/api/v1/admin/users', [
        'name' => 'Rae Role', 'email' => 'rae@pos.test',
        'roles' => [['location_id' => $this->location->id, 'role' => 'cashier']],
    ], $this->headers)->json('data.user.id');

    $oldRoleId = DB::table('roles')->where('name', 'cashier')->where('location_id', $this->location->id)->value('id');
    $this->assertDatabaseHas('model_has_roles', ['model_id' => $userId, 'role_id' => $oldRoleId]);

    $this->patchJson("/api/v1/admin/users/{$userId}", [
        'roles' => [['location_id' => $this->location->id, 'role' => 'supervisor']],
    ], $this->headers)->assertOk();

    $this->assertDatabaseMissing('model_has_roles', ['model_id' => $userId, 'role_id' => $oldRoleId]);
    $newRoleId = DB::table('roles')->where('name', 'supervisor')->where('location_id', $this->location->id)->value('id');
    $this->assertDatabaseHas('model_has_roles', ['model_id' => $userId, 'role_id' => $newRoleId]);

    $row = DB::table('audit_log')->where('action', 'admin.user.update')->where('entity_id', $userId)->orderByDesc('created_at')->first();
    $payload = json_decode($row->payload, true);
    // jsonb reorders keys on round-trip — content-identical, never byte-identical.
    expect($payload['roles'])->toEqual([['location_id' => $this->location->id, 'from' => 'cashier', 'to' => 'supervisor']]);
});

it('refuses self-de-admin with 422 self_lockout', function (): void {
    $this->patchJson("/api/v1/admin/users/{$this->admin->id}", ['is_admin' => false], $this->headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'self_lockout');

    expect($this->admin->refresh()->is_admin)->toBeTrue();
});

it('refuses self-deactivate with 422 self_lockout', function (): void {
    $this->patchJson("/api/v1/admin/users/{$this->admin->id}", ['is_active' => false], $this->headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'self_lockout');

    expect($this->admin->refresh()->is_active)->toBeTrue();
});

it('allows deactivating another admin', function (): void {
    $other = User::factory()->create(['email' => 'other-admin@pos.test', 'is_admin' => true]);

    $this->patchJson("/api/v1/admin/users/{$other->id}", ['is_active' => false], $this->headers)
        ->assertOk();

    expect($other->refresh()->is_active)->toBeFalse();
});

it('surfaces a PIN collision as pin_already_in_use through the create endpoint', function (): void {
    $this->postJson('/api/v1/admin/users', [
        'name' => 'First', 'pin' => '1234',
        'roles' => [['location_id' => $this->location->id, 'role' => 'cashier']],
    ], $this->headers)->assertCreated();

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Second', 'pin' => '1234',
        'roles' => [['location_id' => $this->location->id, 'role' => 'cashier']],
    ], $this->headers)->assertStatus(422)->assertJsonPath('error.code', 'pin_already_in_use');
});

it('a non-admin token gets 403', function (): void {
    $staff = User::factory()->create(['email' => 'staffer@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];

    $this->getJson('/api/v1/admin/users', $headers)->assertStatus(403);
    $this->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'x@pos.test'], $headers)->assertStatus(403);
});

it('lists users with roles read via a direct model_has_roles join', function (): void {
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Listy', 'email' => 'listy@pos.test',
        'roles' => [['location_id' => $this->location->id, 'role' => 'cashier']],
    ], $this->headers)->assertCreated();

    $this->getJson('/api/v1/admin/users', $this->headers)
        ->assertOk()
        ->assertJsonFragment([
            'location_id' => $this->location->id,
            'location_name' => $this->location->name,
            'role' => 'cashier',
        ]);
});

it('rejects an unknown role name', function (): void {
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Bad Role', 'email' => 'badrole@pos.test',
        'roles' => [['location_id' => $this->location->id, 'role' => 'manager']],
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses to null the only email of a user with no PIN — 422, not a raw DB 500', function (): void {
    $userId = $this->postJson('/api/v1/admin/users', [
        'name' => 'Only Email', 'email' => 'onlyemail@pos.test',
    ], $this->headers)->json('data.user.id');

    $this->patchJson("/api/v1/admin/users/{$userId}", ['email' => null], $this->headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'email_or_pin_required');

    expect(User::findOrFail($userId)->email)->toBe('onlyemail@pos.test');
});

it('allows nulling email when a pin is being set in the same request', function (): void {
    $userId = $this->postJson('/api/v1/admin/users', [
        'name' => 'Switching To Pin', 'email' => 'switching@pos.test',
    ], $this->headers)->json('data.user.id');

    $this->patchJson("/api/v1/admin/users/{$userId}", [
        'email' => null, 'pin' => '5678',
        'roles' => [['location_id' => $this->location->id, 'role' => 'cashier']],
    ], $this->headers)->assertOk();

    expect(User::findOrFail($userId)->email)->toBeNull();
});

it('throws loudly — not a silent no-op — when a role is not provisioned at the given location', function (): void {
    // A location that exists (passes the exists:locations,id rule) but was never handed
    // to RoleProvisioner::provisionForLocation() — a seeder/deploy bug, not user input.
    $unprovisioned = Location::factory()->create(['code' => 'UP']);

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Bad Provision', 'email' => 'badprov@pos.test',
        'roles' => [['location_id' => $unprovisioned->id, 'role' => 'cashier']],
    ], $this->headers)->assertStatus(500);
});
