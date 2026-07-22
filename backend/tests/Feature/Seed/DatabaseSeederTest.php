<?php

declare(strict_types=1);

use App\Models\Discount;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Role rows for a user at a location, read straight from model_has_roles —
 *  never through spatie's team-scoped roles() relation (CLAUDE.md gotcha). */
function rolesAt(User $user, Location $location): array
{
    return DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.model_id', $user->getKey())
        ->where('roles.location_id', $location->id)
        ->pluck('roles.name')->all();
}

it('seeds only the grocery catalog by default', function (): void {
    $this->seed();   // pos.seed_catalogs defaults to 'grocery'

    expect(Location::query()->pluck('code')->all())->toBe(['GRC']);

    $grocery = Location::query()->firstOrFail();
    $maria = User::query()->where('name', 'Maria Both')->firstOrFail();
    expect(rolesAt($maria, $grocery))->toBe(['cashier']);
    // No second location, so Maria's supervisor leg degrades away entirely.
    expect(DB::table('model_has_roles')->where('model_id', $maria->getKey())->count())->toBe(1);

    expect(User::query()->where('is_admin', true)->where('email', 'admin@pos.test')->exists())->toBeTrue();
    expect(Discount::query()->pluck('name')->all())->toBe(['10% off', '₱50 off']);
});

it('seeds all three catalogs when configured, with the two-location RBAC story', function (): void {
    config(['pos.seed_catalogs' => 'grocery,restaurant,cafe']);
    $this->seed();

    expect(Location::query()->orderBy('name')->pluck('code')->all())->toBe(['CAF', 'GRC', 'RST']);

    $grocery = Location::query()->where('code', 'GRC')->firstOrFail();
    $restaurant = Location::query()->where('code', 'RST')->firstOrFail();
    $cafe = Location::query()->where('code', 'CAF')->firstOrFail();

    $alice = User::query()->where('name', 'Alice Cashier')->firstOrFail();
    $bob = User::query()->where('name', 'Bob Supervisor')->firstOrFail();
    $maria = User::query()->where('name', 'Maria Both')->firstOrFail();

    expect(rolesAt($alice, $restaurant))->toBe(['cashier']);
    expect(rolesAt($bob, $restaurant))->toBe(['supervisor']);
    expect(rolesAt($bob, $cafe))->toBe(['supervisor']);
    expect(rolesAt($maria, $grocery))->toBe(['cashier']);
    expect(rolesAt($maria, $restaurant))->toBe(['supervisor']);
    expect(rolesAt($maria, $cafe))->toBe([]);
});

it('rejects an unknown catalog name loudly', function (): void {
    config(['pos.seed_catalogs' => 'grocery,mall']);

    expect(fn () => $this->seed())->toThrow(InvalidArgumentException::class, 'mall');
});
