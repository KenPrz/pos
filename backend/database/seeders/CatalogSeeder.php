<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Money\Quantity;
use App\Domain\Rbac\RoleProvisioner;
use App\Domain\Stock\StockLedger;
use App\Models\Category;
use App\Models\Location;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\TaxRate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * One business-type catalog from one committed JSON file: its Manila location, its
 * registers, its categories/modifiers/products/variants, and opening stock through the
 * ledger. Data files live in database/seeders/data/ and are guarded by
 * tests/Unit/SeedDataTest.php; anything structurally wrong here fails loudly rather
 * than half-seeding.
 */
abstract class CatalogSeeder extends Seeder
{
    private const int PH_VAT_MICROS = 120_000;   // 12% — the PH standard rate

    /** Location columns specific to this catalog (name, code, receipt copy). */
    abstract protected function locationAttributes(): array;

    /** Register name => mode, e.g. ['Till 1' => 'retail', 'Till 2' => 'retail']. */
    abstract protected function registerModes(): array;

    /** File name under database/seeders/data/, e.g. 'grocery.json'. */
    abstract protected function dataFile(): string;

    public function run(): void
    {
        $this->seed();
    }

    /** @return array{location: Location, tokens: array<array{string, string}>} */
    public function seed(): array
    {
        $data = $this->loadData();

        // Idempotent ("Safe to re-run") — makes each catalog seeder standalone-runnable.
        $provisioner = app(RoleProvisioner::class);
        $provisioner->provisionGlobal();

        $location = Location::factory()->create([
            'timezone' => 'Asia/Manila',
            'prices_include_tax' => true,   // PH: VAT is in the shelf price
            ...$this->locationAttributes(),
        ]);
        $provisioner->provisionForLocation($location);

        $tokens = [];
        foreach ($this->registerModes() as $name => $mode) {
            $register = Register::factory()->create([
                'location_id' => $location->id,
                'name' => $name,
                'mode' => $mode,
            ]);
            $tokens[] = [
                $location->code.' / '.$name,
                $register->createToken("device:{$register->id}", ['device'])->plainTextToken,
            ];
        }

        $vat = TaxRate::query()->firstOrCreate(
            ['name' => 'VAT 12%'],
            ['rate_micros' => self::PH_VAT_MICROS, 'is_active' => true],
        );
        // Raw agricultural produce is VAT-exempt in the PH — modeled as a zero rate.
        $exempt = TaxRate::query()->firstOrCreate(
            ['name' => 'VAT Exempt'],
            ['rate_micros' => 0, 'is_active' => true],
        );

        $categories = [];
        foreach ($data['categories'] as $c) {
            $categories[$c['name']] = Category::factory()->create([
                'name' => $c['name'],
                'sort_order' => $c['sort_order'],
            ]);
        }

        $groups = [];
        foreach ($data['modifier_groups'] as $g) {
            $group = ModifierGroup::factory()->create([
                'name' => $g['name'],
                'min_select' => $g['min_select'],
                'max_select' => $g['max_select'],
            ]);
            foreach ($g['modifiers'] as $position => $m) {
                Modifier::factory()->create([
                    'group_id' => $group->id,
                    'name' => $m['name'],
                    'price_delta_cents' => $m['price_delta_cents'],
                    'position' => $position,
                ]);
            }
            $groups[$g['name']] = $group;
        }

        $tracked = [];
        foreach ($data['products'] as $p) {
            $product = Product::factory()->create([
                'name' => $p['name'],
                'description' => $p['description'] ?? null,
                'category_id' => $categories[$p['category']]->id,
            ]);

            $attach = [];
            foreach ($p['modifier_groups'] ?? [] as $position => $groupName) {
                $attach[$groups[$groupName]->id] = ['position' => $position];
            }
            if ($attach !== []) {
                $product->modifierGroups()->attach($attach);
            }

            foreach ($p['variants'] as $position => $v) {
                $variant = ProductVariant::factory()->create([
                    'product_id' => $product->id,
                    'name' => $v['name'],
                    'sku' => $v['sku'],
                    'barcode' => $v['barcode'],
                    'price_cents' => $v['price_cents'],
                    'cost_cents' => $v['cost_cents'],
                    'tax_rate_id' => ($v['tax_exempt'] ?? false) ? $exempt->id : $vat->id,
                    'track_inventory' => $v['track_inventory'],
                    'position' => $position,
                ]);
                if ($v['track_inventory']) {
                    $tracked[] = $variant;
                }
            }
        }

        if ($tracked !== []) {
            $ledger = app(StockLedger::class);
            DB::transaction(function () use ($ledger, $tracked, $location): void {
                foreach ($tracked as $variant) {
                    // Through the ledger, so stock_levels.qty = sum(movements) from day one.
                    $ledger->receive($variant->id, $location->id, Quantity::fromString('20'), note: 'seed');
                }
            });
        }

        return ['location' => $location, 'tokens' => $tokens];
    }

    private function loadData(): array
    {
        $path = database_path('seeders/data/'.$this->dataFile());
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new JsonException("Seed data file missing: {$path}");
        }

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
