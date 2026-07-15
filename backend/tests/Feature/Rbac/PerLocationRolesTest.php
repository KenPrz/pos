<?php

declare(strict_types=1);

use App\Domain\Rbac\Permissions;
use App\Domain\Rbac\RoleProvisioner;
use App\Domain\Rbac\Roles;
use App\Models\Location;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/*
| The tests M2 exists to pass. Each one corresponds to money walking out of a till.
| See docs/05-rbac.md.
*/

function actingAtLocation(Location $location): void
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($location->id);
    $registrar->forgetCachedPermissions();
}

beforeEach(function (): void {
    $this->provisioner = app(RoleProvisioner::class);
    $this->provisioner->provisionGlobal();

    $this->downtown = Location::factory()->create(['code' => 'DT']);
    $this->airport = Location::factory()->create(['code' => 'AP']);

    $this->provisioner->provisionForLocation($this->downtown);
    $this->provisioner->provisionForLocation($this->airport);
});

it('does not let a supervisor at one store be a supervisor at another', function (): void {
    // The whole reason teams are enabled. With global roles, a promotion at the airport
    // silently grants comp powers downtown — precisely what the supervisor boundary
    // exists to prevent.
    $maria = User::factory()->withPin('1234')->create();

    actingAtLocation($this->downtown);
    $maria->assignRole(Roles::CASHIER);

    actingAtLocation($this->airport);
    $maria->assignRole(Roles::SUPERVISOR);

    actingAtLocation($this->airport);
    $maria->unsetRelation('roles');
    expect($maria->can(Permissions::ORDER_DISCOUNT_APPLY))->toBeTrue('supervisor at the airport');

    actingAtLocation($this->downtown);
    $maria->unsetRelation('roles');
    expect($maria->can(Permissions::ORDER_DISCOUNT_APPLY))->toBeFalse('but NOT downtown');
});

it('grants a cashier exactly the shift they can run alone', function (): void {
    $alice = User::factory()->withPin('1111')->create();

    actingAtLocation($this->downtown);
    $alice->assignRole(Roles::CASHIER);
    $alice->unsetRelation('roles');

    expect($alice->can(Permissions::PAYMENT_TAKE))->toBeTrue()
        ->and($alice->can(Permissions::ORDER_LINE_ADD))->toBeTrue()
        // A cashier opens and closes their own drawer without a supervisor: requiring one
        // for a routine open means a manager's PIN on a sticky note.
        ->and($alice->can(Permissions::SHIFT_OPEN))->toBeTrue()
        ->and($alice->can(Permissions::SHIFT_CLOSE))->toBeTrue();
});

/*
| Table-driven so that adding a permission to the wrong role fails CI, rather than
| depending on someone noticing in review.
*/
it('denies every money-leaves permission to a cashier', function (string $permission): void {
    $alice = User::factory()->withPin('1111')->create();

    actingAtLocation($this->downtown);
    $alice->assignRole(Roles::CASHIER);
    $alice->unsetRelation('roles');

    expect($alice->can($permission))->toBeFalse("cashier must not have {$permission}");
})->with(Permissions::moneyLeaves());

it('grants every money-leaves permission to a supervisor', function (string $permission): void {
    $bob = User::factory()->withPin('2222')->create();

    actingAtLocation($this->downtown);
    $bob->assignRole(Roles::SUPERVISOR);
    $bob->unsetRelation('roles');

    expect($bob->can($permission))->toBeTrue("supervisor must have {$permission}");
})->with(Permissions::moneyLeaves());

it('gives a supervisor everything a cashier has', function (): void {
    $bob = User::factory()->withPin('2222')->create();

    actingAtLocation($this->downtown);
    $bob->assignRole(Roles::SUPERVISOR);
    $bob->unsetRelation('roles');

    foreach (Permissions::cashier() as $permission) {
        expect($bob->can($permission))->toBeTrue("supervisor must have {$permission}");
    }
});

describe('admin', function (): void {
    it('resolves at every location, having no role anywhere', function (): void {
        // Admin is a flag, not a spatie role: spatie's teams pin every assignment to one
        // location, so a role that spans locations is not expressible. See docs/05-rbac.md.
        $priya = User::factory()->admin()->create();

        foreach ([$this->downtown, $this->airport] as $location) {
            actingAtLocation($location);

            expect($priya->can(Permissions::CATALOG_MANAGE))->toBeTrue()
                ->and($priya->can(Permissions::ORDER_VOID))->toBeTrue()
                ->and($priya->can(Permissions::AUDIT_VIEW))->toBeTrue();
        }

        expect($priya->roles()->count())->toBe(0);
    });

    it('does not grant everyone else everything', function (): void {
        // Gate::before must return null, not false, for non-admins — false would deny
        // outright instead of letting the permission checks run.
        $alice = User::factory()->withPin('1111')->create();

        actingAtLocation($this->downtown);
        $alice->assignRole(Roles::CASHIER);
        $alice->unsetRelation('roles');

        expect($alice->can(Permissions::CATALOG_MANAGE))->toBeFalse()
            ->and($alice->can(Permissions::PAYMENT_TAKE))->toBeTrue();
    });
});

it('seeds roles for a location created after setup', function (): void {
    // A location without roles is a store nobody can be assigned to.
    $newStore = Location::factory()->create(['code' => 'NEW']);
    $this->provisioner->provisionForLocation($newStore);

    $user = User::factory()->withPin('5555')->create();

    actingAtLocation($newStore);
    $user->assignRole(Roles::SUPERVISOR);
    $user->unsetRelation('roles');

    expect($user->can(Permissions::REFUND_CREATE))->toBeTrue();
});

it('reports which locations a person works at without a pivot table', function (): void {
    $maria = User::factory()->withPin('1234')->create();

    actingAtLocation($this->downtown);
    $maria->assignRole(Roles::CASHIER);
    actingAtLocation($this->airport);
    $maria->assignRole(Roles::SUPERVISOR);

    // Holding a role at a location *is* being assigned there.
    expect($maria->locationIds())
        ->toHaveCount(2)
        ->toContain($this->downtown->id)
        ->toContain($this->airport->id);
});
