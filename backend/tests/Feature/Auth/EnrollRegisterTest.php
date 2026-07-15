<?php

declare(strict_types=1);

use App\Domain\Rbac\RoleProvisioner;
use App\Domain\Rbac\Roles;
use App\Models\Location;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;

/*
| M2's stated done-criteria, over real HTTP: enrol a register, log in with a PIN, and be
| refused when the permission is missing. See docs/06-roadmap.md.
*/

beforeEach(function (): void {
    $provisioner = app(RoleProvisioner::class);
    $provisioner->provisionGlobal();

    $this->location = Location::factory()->create(['code' => 'DT']);
    $provisioner->provisionForLocation($this->location);
});

it('enrols a register and returns a device token exactly once', function (): void {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson('/api/v1/registers/enroll', [
        'location_id' => $this->location->id,
        'name' => 'Till 1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.register.name', 'Till 1')
        ->assertJsonPath('data.register.location_id', $this->location->id)
        ->assertJsonStructure(['data' => ['device_token', 'register' => ['id', 'name']]]);

    // The token is real and belongs to the register — the terminal is the token's owner.
    $token = PersonalAccessToken::findToken($response->json('data.device_token'));

    expect($token)->not->toBeNull()
        ->and($token->can('device'))->toBeTrue()
        ->and($token->tokenable_id)->toBe($response->json('data.register.id'))
        // No expiry: a till that logs itself out overnight cannot open in the morning.
        ->and($token->expires_at)->toBeNull();
});

it('refuses a cashier trying to enrol a terminal', function (): void {
    $alice = User::factory()->withPin('1111')->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->location->id);
    $alice->assignRole(Roles::CASHIER);
    $registrar->forgetCachedPermissions();

    $this->actingAs($alice)->postJson('/api/v1/registers/enroll', [
        'location_id' => $this->location->id,
        'name' => 'Rogue Till',
    ])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
});

it('refuses an unauthenticated enrolment', function (): void {
    $this->postJson('/api/v1/registers/enroll', [
        'location_id' => $this->location->id,
        'name' => 'Rogue Till',
    ])->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('validates the location exists', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/v1/registers/enroll', [
        'location_id' => (string) Str::uuid7(),
        'name' => 'Till 1',
    ])->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('writes an audit row for the enrolment', function (): void {
    // Everything a permission gates leaves a trail. See docs/05-rbac.md.
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/v1/registers/enroll', [
        'location_id' => $this->location->id,
        'name' => 'Till 1',
    ])->assertCreated();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'register.enroll',
        'user_id' => $admin->id,
        'entity_type' => 'Register',
    ]);
});
