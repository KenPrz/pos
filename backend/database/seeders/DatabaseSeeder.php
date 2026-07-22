<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Rbac\RoleProvisioner;
use App\Domain\Rbac\Roles;
use App\Models\Discount;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * A believable Manila business to develop against. POS_SEED_CATALOGS (default
 * 'grocery') picks which business-type catalogs to build — grocery, restaurant, cafe —
 * each with its own location, registers, and JSON-backed catalog (see CatalogSeeder).
 * Staff cover every enabled location; Maria's split role needs at least two.
 *
 * PINs and device tokens are printed at the end. Development only — never run this
 * anywhere real.
 */
class DatabaseSeeder extends Seeder
{
    private const array CATALOGS = [
        'grocery' => GrocerySeeder::class,
        'restaurant' => RestaurantSeeder::class,
        'cafe' => CafeSeeder::class,
    ];

    public function run(): void
    {
        app(RoleProvisioner::class)->provisionGlobal();

        $names = array_values(array_filter(array_map('trim', explode(',', (string) config('pos.seed_catalogs')))));
        if ($names === []) {
            throw new InvalidArgumentException('POS_SEED_CATALOGS selects no catalogs — expected a comma-separated subset of: '.implode(', ', array_keys(self::CATALOGS)));
        }

        $locations = [];
        $tokens = [];
        foreach ($names as $name) {
            $class = self::CATALOGS[$name] ?? throw new InvalidArgumentException(
                "Unknown seed catalog '{$name}' — expected a comma-separated subset of: ".implode(', ', array_keys(self::CATALOGS)),
            );
            $result = (new $class)->seed();
            $locations[] = $result['location'];
            $tokens = [...$tokens, ...$result['tokens']];
        }

        $this->seedStaff($locations);
        $this->seedDiscounts();

        $everywhere = implode(', ', array_map(fn (Location $l): string => $l->code, $locations));
        $mariaRoles = 'cashier @ '.$locations[0]->code
            .(isset($locations[1]) ? ', supervisor @ '.$locations[1]->code : '');

        $this->command?->newLine();
        $this->command?->info('Seeded catalogs: '.implode(', ', $names).'. Development PINs:');
        $this->command?->table(
            ['Person', 'PIN', 'Role'],
            [
                ['Alice', '1111', 'cashier @ '.$everywhere],
                ['Bob', '2222', 'supervisor @ '.$everywhere],
                ['Maria', '3333', $mariaRoles],
                ['Priya', '4444', 'admin (global)'],
            ],
        );

        $this->command?->newLine();
        $this->command?->info('Device tokens (paste one into the register SPA):');
        $this->command?->table(['Register', 'Device token'], $tokens);

        $this->command?->newLine();
        $this->command?->info('Back-office login (POST /api/v1/admin/login):');
        $this->command?->table(['Email', 'Password'], [['admin@pos.test', 'password']]);
    }

    /** @param array<Location> $locations */
    private function seedStaff(array $locations): void
    {
        $registrar = app(PermissionRegistrar::class);

        $alice = User::factory()->withPin('1111')->create(['name' => 'Alice Cashier']);
        $bob = User::factory()->withPin('2222')->create(['name' => 'Bob Supervisor']);

        // The whole reason roles are scoped per location: Maria is a cashier at the
        // first location and a supervisor at the second (when one exists); her
        // supervisor powers must not follow her home. See docs/05-rbac.md.
        $maria = User::factory()->withPin('3333')->create(['name' => 'Maria Both']);

        // Admin is a flag, not a role: the one capability that spans every location,
        // which spatie's teams cannot express. See docs/05-rbac.md. Priya has both a
        // PIN (to work a till) and a password (for the back office).
        User::factory()->admin()->withPin('4444')->create([
            'name' => 'Priya Admin',
            'email' => 'admin@pos.test',
            'password_hash' => Hash::make('password'),
        ]);

        // Role assignment is per team, so the team context is set before each one.
        foreach ($locations as $i => $location) {
            $registrar->setPermissionsTeamId($location->id);
            $alice->assignRole(Roles::CASHIER);
            $bob->assignRole(Roles::SUPERVISOR);
            if ($i === 0) {
                $maria->assignRole(Roles::CASHIER);
            }
            if ($i === 1) {
                $maria->assignRole(Roles::SUPERVISOR);
            }
        }

        $registrar->forgetCachedPermissions();
    }

    /** The two discounts a cashier reaches for most: a round percentage, and a flat comp. */
    private function seedDiscounts(): void
    {
        Discount::factory()->percent(100_000)->create(['name' => '10% off']);
        Discount::factory()->fixed(5000)->create(['name' => '₱50 off']);
    }
}
