<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Action tests run against real Postgres (see phpunit.xml) — never SQLite,
| per docs/01-architecture.md.
|
| Unit tests get no TestCase and no database on purpose: the money primitives in M1 are
| pure integer functions and must stay fast enough to run on every keystroke.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

use App\Domain\Rbac\RoleProvisioner;
use App\Models\Location;
use App\Models\Register;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/** A location with the role/permission catalog provisioned. */
function provisionedLocation(array $attrs = []): Location
{
    $location = Location::factory()->create($attrs);
    $provisioner = app(RoleProvisioner::class);
    $provisioner->provisionGlobal();
    $provisioner->provisionForLocation($location);

    return $location;
}

function registerAt(Location $location): Register
{
    return Register::factory()->create(['location_id' => $location->id]);
}

function staffWithRole(Location $location, string $role): User
{
    $user = User::factory()->create();
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($location->id);
    $user->assignRole($role);
    $registrar->forgetCachedPermissions();

    return $user;
}

/**
 * Device + staff headers for HTTP tests. The staff token's name and ability string must
 * match what StaffLogin issues and EnsureStaffSession checks: name "staff:{register id}",
 * ability "register:{id}".
 */
function staffHeaders(Register $register, User $user): array
{
    $device = $register->createToken("device:{$register->id}", ['device'])->plainTextToken;
    $staff = $user->createToken("staff:{$register->id}", ["register:{$register->id}"], now()->addMinutes(480))->plainTextToken;

    return ['Authorization' => "Bearer {$device}", 'X-Staff-Token' => $staff];
}
