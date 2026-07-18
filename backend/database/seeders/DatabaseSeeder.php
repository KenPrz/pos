<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Money\Quantity;
use App\Domain\Rbac\RoleProvisioner;
use App\Domain\Rbac\Roles;
use App\Domain\Stock\StockLedger;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Location;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * A believable business to develop against: two locations that differ in the ways that
 * matter (timezone, tax mode), staff at every role, and a catalog covering both retail
 * (barcoded variants) and food service (modifiers).
 *
 * PINs are printed at the end. Development only — never run this anywhere real.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(RoleProvisioner::class);
        $provisioner->provisionGlobal();

        // Two locations that disagree on the two things a general-purpose POS must
        // handle: which day it is, and whether the shelf price includes tax.
        $downtown = Location::factory()->create([
            'name' => 'Downtown',
            'code' => 'DT',
            'timezone' => 'America/New_York',
            'prices_include_tax' => false,          // US: tax added at checkout
            'receipt_header' => 'Downtown Store',
            'receipt_footer' => 'Thanks for shopping with us!',
        ]);

        $london = Location::factory()->create([
            'name' => 'London',
            'code' => 'LDN',
            'timezone' => 'Europe/London',
            'prices_include_tax' => true,           // UK: VAT already in the shelf price
            'receipt_header' => 'London Store',
            'receipt_footer' => 'VAT included.',
        ]);

        $tokens = [];
        foreach ([$downtown, $london] as $location) {
            $provisioner->provisionForLocation($location);

            foreach (['Till 1', 'Till 2'] as $name) {
                $register = Register::factory()->create([
                    'location_id' => $location->id,
                    'name' => $name,
                    'mode' => $name === 'Till 2' ? 'food' : 'retail',
                ]);
                $tokens[] = [
                    $location->code.' / '.$name,
                    $register->createToken("device:{$register->id}", ['device'])->plainTextToken,
                ];
            }
        }

        $this->seedStaff($downtown, $london);
        $tracked = $this->seedCatalog();
        $this->seedDiscounts();

        $ledger = app(StockLedger::class);
        DB::transaction(function () use ($ledger, $tracked, $downtown, $london): void {
            foreach ($tracked as $variant) {
                foreach ([$downtown, $london] as $location) {
                    // Through the ledger, so stock_levels.qty = sum(movements) from day one.
                    $ledger->receive($variant->id, $location->id, Quantity::fromString('20'), note: 'seed');
                }
            }
        });

        $this->command?->newLine();
        $this->command?->info('Seeded. Development PINs:');
        $this->command?->table(
            ['Person', 'PIN', 'Role'],
            [
                ['Alice', '1111', 'cashier @ Downtown'],
                ['Bob', '2222', 'supervisor @ Downtown'],
                ['Maria', '3333', 'cashier @ Downtown, supervisor @ London'],
                ['Priya', '4444', 'admin (global)'],
            ],
        );

        $this->command?->newLine();
        $this->command?->info('Device tokens (paste one into the register SPA):');
        $this->command?->table(['Register', 'Device token'], $tokens);

        $this->command?->newLine();
        $this->command?->info('Back-office login (POST /api/v1/admin/login):');
        $this->command?->table(['Email', 'Password'], [['admin@pos.test', 'admin-dev-password']]);
    }

    private function seedStaff(Location $downtown, Location $london): void
    {
        $registrar = app(PermissionRegistrar::class);

        $alice = User::factory()->withPin('1111')->create(['name' => 'Alice Cashier']);
        $bob = User::factory()->withPin('2222')->create(['name' => 'Bob Supervisor']);

        // The whole reason roles are scoped per location. Maria is a cashier at Downtown
        // and a supervisor at London; her supervisor powers must not follow her home.
        // With global roles this is a fraud hole. See docs/05-rbac.md.
        $maria = User::factory()->withPin('3333')->create(['name' => 'Maria Both']);

        // Admin is a flag, not a role: it is the one capability that spans every
        // location, and spatie's teams cannot express that. See docs/05-rbac.md.
        // Priya has both a PIN (to work a till) and a password (for the back office).
        User::factory()->admin()->withPin('4444')->create([
            'name' => 'Priya Admin',
            'email' => 'admin@pos.test',
            'password_hash' => Hash::make('admin-dev-password'),
        ]);

        // Role assignment is per team, so the team context is set before each one.
        $registrar->setPermissionsTeamId($downtown->id);
        $alice->assignRole(Roles::CASHIER);
        $bob->assignRole(Roles::SUPERVISOR);
        $maria->assignRole(Roles::CASHIER);

        $registrar->setPermissionsTeamId($london->id);
        $maria->assignRole(Roles::SUPERVISOR);

        $registrar->forgetCachedPermissions();
    }

    /**
     * @return array<ProductVariant>
     */
    private function seedCatalog(): array
    {
        $standardVat = TaxRate::factory()->create(['name' => 'Standard VAT', 'rate_micros' => 200_000]);
        $nyc = TaxRate::factory()->nyc()->create();

        $drinks = Category::factory()->create(['name' => 'Drinks', 'sort_order' => 1]);
        $apparel = Category::factory()->create(['name' => 'Apparel', 'sort_order' => 2]);
        $food = Category::factory()->create(['name' => 'Food', 'sort_order' => 3]);

        // Food service: one sellable thing, choices made at order time.
        $milk = ModifierGroup::factory()->required()->create(['name' => 'Milk']);
        Modifier::factory()->create(['group_id' => $milk->id, 'name' => 'Whole', 'price_delta_cents' => 0, 'position' => 0]);
        Modifier::factory()->create(['group_id' => $milk->id, 'name' => 'Oat', 'price_delta_cents' => 60, 'position' => 1]);
        // Signed delta: 'no milk, -20c' is a real modifier and the sign is the meaning.
        Modifier::factory()->create(['group_id' => $milk->id, 'name' => 'None', 'price_delta_cents' => -20, 'position' => 2]);

        $extras = ModifierGroup::factory()->create(['name' => 'Extras', 'min_select' => 0, 'max_select' => 3]);
        Modifier::factory()->create(['group_id' => $extras->id, 'name' => 'Extra shot', 'price_delta_cents' => 80]);
        Modifier::factory()->create(['group_id' => $extras->id, 'name' => 'Syrup', 'price_delta_cents' => 50]);

        $latte = Product::factory()->create(['name' => 'Latte', 'category_id' => $drinks->id]);
        $latte->modifierGroups()->attach([$milk->id => ['position' => 0], $extras->id => ['position' => 1]]);

        // A lone 'Default' variant, hidden by the UI. The sale path always resolves to a
        // variant, so there is no "does this product have options" branch.
        ProductVariant::factory()->untracked()->create([
            'product_id' => $latte->id,
            'name' => 'Default',
            'sku' => 'LATTE',
            'barcode' => null,
            'price_cents' => 350,
            'tax_rate_id' => $standardVat->id,
        ]);

        // Retail: real variants — each a distinct thing you count on a shelf.
        $tshirt = Product::factory()->create(['name' => 'T-Shirt', 'category_id' => $apparel->id]);

        $tracked = [];
        $sizes = [['Blue / S', 'S', '012345678905'], ['Blue / M', 'M', '012345678912'], ['Blue / L', 'L', '012345678929']];

        foreach ($sizes as $i => [$name, $size, $barcode]) {
            $tracked[] = ProductVariant::factory()->create([
                'product_id' => $tshirt->id,
                'name' => $name,
                'sku' => 'TSHIRT-BLUE-'.$size,
                'barcode' => $barcode,
                'price_cents' => 1999,
                'cost_cents' => 800,
                'tax_rate_id' => $nyc->id,
                'position' => $i,
            ]);
        }

        // Sold by weight: proves quantities are not integers.
        $cheese = Product::factory()->create(['name' => 'Cheddar (per kg)', 'category_id' => $food->id]);
        $tracked[] = ProductVariant::factory()->create([
            'product_id' => $cheese->id,
            'name' => 'Default',
            'sku' => 'CHEESE-KG',
            'barcode' => '012345678936',
            'price_cents' => 2400,
            'tax_rate_id' => $standardVat->id,
        ]);

        return $tracked;
    }

    /** The two discounts a cashier reaches for most: a round percentage, and a flat comp. */
    private function seedDiscounts(): void
    {
        Discount::factory()->percent(100_000)->create(['name' => '10% off']);
        Discount::factory()->fixed(500)->create(['name' => '$5 off']);
    }
}
