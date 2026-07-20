<?php

// backend/tests/Feature/Admin/LocationRegisterTest.php
declare(strict_types=1);

use App\Models\Location;
use App\Models\Register;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

// --- Locations -------------------------------------------------------------

it('creates, lists, and updates a location end to end', function (): void {
    $create = $this->postJson('/api/v1/admin/locations', [
        'name' => 'Downtown',
        'code' => 'DT',
        'timezone' => 'America/New_York',
        'prices_include_tax' => false,
        'receipt_header' => 'Thanks for coming!',
        'receipt_footer' => 'See you again.',
    ], $this->headers)->assertCreated();
    $id = $create->json('data.location.id');

    $this->getJson('/api/v1/admin/locations', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.items.0.name', 'Downtown')
        ->assertJsonPath('data.items.0.receipt_header', 'Thanks for coming!');

    $this->patchJson("/api/v1/admin/locations/{$id}", ['receipt_footer' => 'Come back soon.'], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.location.receipt_footer', 'Come back soon.');

    expect(Location::findOrFail($id)->receipt_footer)->toBe('Come back soon.');

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.location.create', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.location.update', 'entity_id' => $id]);
});

it('rejects a bad timezone on create and update', function (): void {
    $this->postJson('/api/v1/admin/locations', [
        'name' => 'Uptown', 'code' => 'UT', 'timezone' => 'Nowhere/Fake',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    $location = Location::factory()->create();
    $this->patchJson("/api/v1/admin/locations/{$location->id}", ['timezone' => 'Nowhere/Fake'], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('lists inactive locations too', function (): void {
    $location = Location::factory()->create(['is_active' => false]);

    $this->getJson('/api/v1/admin/locations', $this->headers)
        ->assertOk()
        ->assertJsonFragment(['id' => $location->id, 'is_active' => false]);
});

it('a non-admin token gets 403 on location routes', function (): void {
    $staff = User::factory()->create(['email' => 's2@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];
    $this->getJson('/api/v1/admin/locations', $headers)->assertStatus(403);
});

// --- Registers ---------------------------------------------------------

it('creates, lists, and updates a register — mode flip audits', function (): void {
    $location = Location::factory()->create();

    $create = $this->postJson('/api/v1/admin/registers', [
        'location_id' => $location->id,
        'name' => 'Till 1',
    ], $this->headers)->assertCreated();
    $id = $create->json('data.register.id');
    expect($create->json('data.register.mode'))->toBe('retail');

    $this->getJson('/api/v1/admin/registers', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.items.0.name', 'Till 1');

    $this->patchJson("/api/v1/admin/registers/{$id}", ['mode' => 'food'], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.register.mode', 'food');

    expect(Register::findOrFail($id)->mode)->toBe('food');
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.register.create', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.register.update', 'entity_id' => $id]);
});

it('refuses to change location_id on a register', function (): void {
    $register = Register::factory()->create();
    $other = Location::factory()->create();

    $this->patchJson("/api/v1/admin/registers/{$register->id}", ['location_id' => $other->id], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('enforces register name uniqueness per location but allows reuse across locations', function (): void {
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();

    $this->postJson('/api/v1/admin/registers', ['location_id' => $locationA->id, 'name' => 'Front'], $this->headers)
        ->assertCreated();

    // same name, same location -> rejected
    $this->postJson('/api/v1/admin/registers', ['location_id' => $locationA->id, 'name' => 'Front'], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    // same name, different location -> fine
    $this->postJson('/api/v1/admin/registers', ['location_id' => $locationB->id, 'name' => 'Front'], $this->headers)
        ->assertCreated();
});

it('lets a register keep its own name on update (ignores self in the uniqueness check)', function (): void {
    $location = Location::factory()->create();
    $register = Register::factory()->create(['location_id' => $location->id, 'name' => 'Front']);

    $this->patchJson("/api/v1/admin/registers/{$register->id}", ['name' => 'Front', 'is_active' => false], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.register.is_active', false);
});

it('refuses a duplicate register name against a sibling on update', function (): void {
    $location = Location::factory()->create();
    Register::factory()->create(['location_id' => $location->id, 'name' => 'Front']);
    $back = Register::factory()->create(['location_id' => $location->id, 'name' => 'Back']);

    $this->patchJson("/api/v1/admin/registers/{$back->id}", ['name' => 'Front'], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

// --- Activation code issue ------------------------------------------------

it('issues an activation code and locks the register out: device and staff tokens die', function (): void {
    $location = Location::factory()->create();
    $register = Register::factory()->create(['location_id' => $location->id]);
    $deviceToken = $register->createToken("device:{$register->id}", ['device'])->plainTextToken;
    $staff = User::factory()->create();
    $staffToken = $staff->createToken("staff:{$register->id}", ["register:{$register->id}"], now()->addMinutes(480))->plainTextToken;

    $this->getJson('/api/v1/catalog', ['Authorization' => "Bearer {$deviceToken}"])->assertOk();

    $response = $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers)
        ->assertCreated();

    expect($response->json('data.activation_code'))->toMatch('/^[23456789A-Z]{5}-[23456789A-Z]{5}$/')
        ->and($response->json('data.expires_at'))->not->toBeNull();

    // Full lockout, immediately: the device token is dead...
    $this->getJson('/api/v1/catalog', ['Authorization' => "Bearer {$deviceToken}"])->assertStatus(401);
    // ...and the staff session bound to this register died with it.
    expect(PersonalAccessToken::findToken($staffToken))->toBeNull();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.register.code_issue', 'entity_id' => $register->id]);
});

it('a second issue supersedes the first code', function (): void {
    $location = Location::factory()->create();
    $register = Register::factory()->create(['location_id' => $location->id]);

    $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers)->assertCreated();
    $first = $register->fresh()->activation_code_lookup;

    $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers)->assertCreated();

    expect($register->fresh()->activation_code_lookup)->not->toBe($first);
});

it('a non-admin cannot issue an activation code', function (): void {
    $location = Location::factory()->create();
    $register = Register::factory()->create(['location_id' => $location->id]);
    $staff = User::factory()->create(['email' => 'nonadmin@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];

    $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $headers)->assertStatus(403);
});

it('a non-admin token gets 403 on register routes', function (): void {
    $staff = User::factory()->create(['email' => 's3@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];
    $this->getJson('/api/v1/admin/registers', $headers)->assertStatus(403);
});

it('reports activation state on the admin register list', function (): void {
    $location = Location::factory()->create();
    $register = Register::factory()->create(['location_id' => $location->id]);

    // Fresh register: no token, no code.
    $this->getJson('/api/v1/admin/registers', $this->headers)
        ->assertJsonPath('data.items.0.activation.state', 'not_enrolled');

    // Code issued → pending, with its expiry surfaced.
    $code = $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers)
        ->json('data.activation_code');
    $response = $this->getJson('/api/v1/admin/registers', $this->headers);
    $response->assertJsonPath('data.items.0.activation.state', 'code_pending');
    expect($response->json('data.items.0.activation.code_expires_at'))->not->toBeNull();

    // Redeemed → enrolled.
    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])->assertCreated();
    $this->getJson('/api/v1/admin/registers', $this->headers)
        ->assertJsonPath('data.items.0.activation.state', 'enrolled');

    // A new code locks it out again; 8 days later that code has expired.
    $this->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $this->headers);
    $this->travel(8)->days();
    $this->getJson('/api/v1/admin/registers', $this->headers)
        ->assertJsonPath('data.items.0.activation.state', 'code_expired');
});
