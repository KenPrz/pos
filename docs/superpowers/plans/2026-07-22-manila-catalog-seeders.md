# Manila Catalog Seeders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Downtown/London demo seed with three env-selectable Manila catalog seeders (Grocery 200 items / Restaurant 30 / Cafe 20) backed by committed, internet-sourced JSON data, priced in Philippine pesos, with all three e2e scripts re-anchored and green.

**Architecture:** An abstract `CatalogSeeder` owns the JSON-driven mechanics (location, registers, categories, modifier groups, products, variants, ledger stock, device tokens); three thin subclasses parameterize it. `DatabaseSeeder` reads `config('pos.seed_catalogs')` (from `POS_SEED_CATALOGS`, default `grocery`), runs the selected seeders, then assigns staff across whichever locations now exist. Data files live in `backend/database/seeders/data/*.json` and are guarded by a pure (no-DB) validation test.

**Tech Stack:** Laravel 13.20 seeders + factories, Pest (real Postgres, never SQLite), bash+curl+jq for the one-shot data generator, Open Food Facts PH API for grocery sourcing.

**Spec:** `docs/superpowers/specs/2026-07-22-manila-catalog-seeders-design.md`

## Global Constraints

- Money is integer centavos everywhere (`price_cents`, `cost_cents`, `price_delta_cents`). Never a float.
- Never call `env()` outside `config/` — `tests/Arch/ConventionsTest.php` enforces this mechanically. Seeders read `config('pos.seed_catalogs')`.
- Every PHP file starts with `declare(strict_types=1);`.
- Backend tests run against real Postgres (`pos_test`). Bring the db up first: `docker compose -f compose.dev.yml up -d db`, and create `pos_test` if missing (`docker compose -f compose.dev.yml exec db psql -U pos -d pos -c "create database pos_test owner pos;"` — ignore "already exists").
- Run backend tests natively as `cd backend && ./vendor/bin/pest` (filtered with `--filter=` per task). If `vendor/` is missing, `composer install` first. If PHP isn't available natively, run inside the container instead: `docker compose -f compose.dev.yml exec --user pos api ./vendor/bin/pest --filter=...` (requires `make dev` up).
- Commit messages: repo style (`Seeder: ...`, `e2e: ...`, `Docs: ...`), imperative, **no Co-Authored-By or any attribution trailer** (hard user rule).
- Never read spatie role assignments through `roles()` — query `model_has_roles` directly (CLAUDE.md gotcha). Applies to the new DatabaseSeeder tests.
- Eloquent `create()` never hydrates DB column defaults — set columns explicitly.
- The three Manila locations: `Asia/Manila` timezone, `prices_include_tax = true`.
- Fixed anchor data (tests and e2e scripts pin these — copy exactly):
  - `GRC-NESCAFE-100` "Nescafé Classic 100g", barcode `4809990000016`, price_cents 18500
  - `GRC-LUCKYME-60` "Lucky Me! Pancit Canton Original 60g", barcode `4809990000023`, price_cents 1500
  - `GRC-TUNA-420` "Century Tuna Flakes in Oil 420g", barcode `4809990000030`, price_cents 25000
  - `GRC-BANGUS-KG` "Bangus (per kg)", barcode `4809990000047`, price_cents 22000, tax_exempt
  - `RST-ADOBO-CHK` "Chicken Adobo", price_cents 22000, required group "Rice" (Plain Rice +0 / Garlic Rice +2000 / No Rice −1500), optional group "Add-ons" min 0 max 3 (Extra Egg +2500 / Chicharon Bits +3000 / Extra Sauce +1500)
  - `RST-GARLIC-RICE` "Garlic Fried Rice" (no required groups), `RST-HALOHALO` "Halo-Halo" (no groups)

---

### Task 1: Config plumbing — `pos.seed_catalogs` and PHP currency

**Files:**
- Modify: `backend/config/pos.php` (after the `'currency'` line, ~line 24)
- Modify: `backend/.env.example` (lines ~31)
- Modify: `compose.dev.yml` (api service `POS_CURRENCY`, line ~62)

**Interfaces:**
- Produces: `config('pos.seed_catalogs')` — string, comma-separated catalog names, default `'grocery'`. Consumed by Task 8's DatabaseSeeder and its tests.

- [ ] **Step 1: Add the config key**

In `backend/config/pos.php`, directly after the `'currency' => env('POS_CURRENCY'),` line, add:

```php
    // Which demo catalogs `php artisan migrate:fresh --seed` builds, comma-separated:
    // any of grocery, restaurant, cafe. Dev-only — the seeder is never run anywhere
    // real. Each enabled catalog brings its own Manila location, registers, and stock.
    'seed_catalogs' => env('POS_SEED_CATALOGS', 'grocery'),
```

- [ ] **Step 2: Update backend/.env.example**

Change line 31 `POS_CURRENCY=USD` to:

```
POS_CURRENCY=PHP
```

and after the `POS_BUSINESS_TAX_ID=` line add:

```
# Seeder catalogs: comma-separated subset of grocery,restaurant,cafe
POS_SEED_CATALOGS=grocery
```

- [ ] **Step 3: Update compose.dev.yml**

In the api service environment, change `POS_CURRENCY: USD` to `POS_CURRENCY: PHP`. Do NOT add `POS_SEED_CATALOGS` here — it flows in per-command via `docker compose exec -e` (Task 9), so a plain `make seed` stays on the config default.

- [ ] **Step 4: Verify the config loads**

Run: `cd backend && php artisan tinker --execute="var_dump(config('pos.seed_catalogs'));"` (or in the api container with `--user pos`).
Expected: `string(7) "grocery"`

- [ ] **Step 5: Commit**

```bash
git add backend/config/pos.php backend/.env.example compose.dev.yml
git commit -m "Config: pos.seed_catalogs selects seeder catalogs; dev currency to PHP"
```

---

### Task 2: Seed-data validation test (fails until Tasks 3–5 land)

**Files:**
- Create: `backend/tests/Unit/SeedDataTest.php`

**Interfaces:**
- Consumes: `backend/database/seeders/data/{grocery,restaurant,cafe}.json` (created in Tasks 3–5).
- Produces: the JSON schema contract every data file must satisfy:

```json
{
  "categories":      [{"name": "Beverages", "sort_order": 1}],
  "modifier_groups": [{"name": "Rice", "min_select": 1, "max_select": 1,
                       "modifiers": [{"name": "Plain Rice", "price_delta_cents": 0}]}],
  "products": [{
      "name": "Chicken Adobo", "description": "…or null", "category": "Mains",
      "modifier_groups": ["Rice", "Add-ons"],
      "variants": [{"name": "Default", "sku": "RST-ADOBO-CHK", "barcode": null,
                    "price_cents": 22000, "cost_cents": null,
                    "track_inventory": false, "tax_exempt": false}]}]
}
```

`modifier_groups` on a product and `description` are optional (default `[]`/`null`); every variant field shown is required except `cost_cents` (nullable) and `barcode` (nullable).

- [ ] **Step 1: Write the failing test**

Unit tests have no TestCase and no Laravel app (see `backend/tests/Pest.php`), so use `__DIR__`-relative paths. Create `backend/tests/Unit/SeedDataTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Guards the committed seed data files. Pure file validation — no database — so data
 * drift breaks CI in milliseconds, not at demo time. Counts, uniqueness, EAN-13
 * checksums, referential integrity, and the anchors the e2e scripts pin.
 */
const SEED_DATA_DIR = __DIR__.'/../../database/seeders/data';

function seedDataFile(string $name): array
{
    $path = SEED_DATA_DIR."/{$name}.json";
    expect(file_exists($path))->toBeTrue("missing {$path}");

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<array<string, mixed>> every variant in the file, flattened */
function seedVariants(array $data): array
{
    return array_merge(...array_map(fn (array $p): array => $p['variants'], $data['products']));
}

function ean13IsValid(string $code): bool
{
    if (preg_match('/^\d{13}$/', $code) !== 1) {
        return false;
    }
    $sum = 0;
    foreach (str_split(substr($code, 0, 12)) as $i => $digit) {
        $sum += ((int) $digit) * ($i % 2 === 0 ? 1 : 3);
    }

    return (10 - ($sum % 10)) % 10 === (int) $code[12];
}

test('grocery data holds exactly 200 sellable items', function (): void {
    expect(seedVariants(seedDataFile('grocery')))->toHaveCount(200);
});

test('restaurant data holds exactly 30 menu items, each described', function (): void {
    $data = seedDataFile('restaurant');
    expect($data['products'])->toHaveCount(30);
    foreach ($data['products'] as $product) {
        expect($product['description'])->toBeString()->not->toBe('');
        expect($product['variants'])->toHaveCount(1);
    }
});

test('cafe data holds exactly 20 items', function (): void {
    expect(seedDataFile('cafe')['products'])->toHaveCount(20);
});

test('skus and barcodes are unique across all three files', function (): void {
    $skus = [];
    $barcodes = [];
    foreach (['grocery', 'restaurant', 'cafe'] as $file) {
        foreach (seedVariants(seedDataFile($file)) as $variant) {
            $skus[] = $variant['sku'];
            if (($variant['barcode'] ?? null) !== null) {
                $barcodes[] = $variant['barcode'];
            }
        }
    }
    expect($skus)->toBe(array_values(array_unique($skus)));
    expect($barcodes)->toBe(array_values(array_unique($barcodes)));
});

test('every grocery barcode is a checksum-valid EAN-13', function (): void {
    foreach (seedVariants(seedDataFile('grocery')) as $variant) {
        expect(ean13IsValid((string) $variant['barcode']))
            ->toBeTrue("bad EAN-13 {$variant['barcode']} on {$variant['sku']}");
    }
});

test('every file is internally consistent', function (): void {
    foreach (['grocery', 'restaurant', 'cafe'] as $file) {
        $data = seedDataFile($file);
        $categories = array_column($data['categories'], 'name');
        $groups = array_column($data['modifier_groups'], 'name');
        expect($categories)->toBe(array_values(array_unique($categories)));

        foreach ($data['modifier_groups'] as $group) {
            expect($group['min_select'])->toBeInt();
            foreach ($group['modifiers'] as $modifier) {
                expect($modifier['price_delta_cents'])->toBeInt();
            }
        }
        foreach ($data['products'] as $product) {
            expect(in_array($product['category'], $categories, true))
                ->toBeTrue("{$file}: unknown category {$product['category']} on {$product['name']}");
            foreach ($product['modifier_groups'] ?? [] as $ref) {
                expect(in_array($ref, $groups, true))
                    ->toBeTrue("{$file}: unknown modifier group {$ref} on {$product['name']}");
            }
            foreach ($product['variants'] as $variant) {
                expect($variant['price_cents'])->toBeInt()->toBeGreaterThan(0);
                expect($variant['sku'])->toBeString()->not->toBe('');
                expect($variant['track_inventory'])->toBeBool();
            }
        }
    }
});

test('the anchors the e2e scripts pin are present and exact', function (): void {
    $bySku = [];
    foreach (['grocery', 'restaurant', 'cafe'] as $file) {
        foreach (seedVariants(seedDataFile($file)) as $variant) {
            $bySku[$variant['sku']] = $variant;
        }
    }

    expect($bySku['GRC-NESCAFE-100']['barcode'])->toBe('4809990000016');
    expect($bySku['GRC-NESCAFE-100']['price_cents'])->toBe(18500);
    expect($bySku['GRC-LUCKYME-60']['barcode'])->toBe('4809990000023');
    expect($bySku['GRC-LUCKYME-60']['price_cents'])->toBe(1500);
    expect($bySku['GRC-TUNA-420']['barcode'])->toBe('4809990000030');
    expect($bySku['GRC-TUNA-420']['price_cents'])->toBe(25000);
    expect($bySku['GRC-BANGUS-KG']['tax_exempt'])->toBeTrue();
    expect($bySku['RST-ADOBO-CHK']['price_cents'])->toBe(22000);
    expect($bySku)->toHaveKeys(['RST-GARLIC-RICE', 'RST-HALOHALO']);

    $restaurant = seedDataFile('restaurant');
    $rice = collect($restaurant['modifier_groups'])->firstWhere('name', 'Rice');
    expect($rice['min_select'])->toBe(1)->and($rice['max_select'])->toBe(1);
    $deltas = array_column($rice['modifiers'], 'price_delta_cents', 'name');
    expect($deltas['Plain Rice'])->toBe(0);
    expect($deltas['Garlic Rice'])->toBe(2000);
    expect($deltas['No Rice'])->toBe(-1500);

    $addons = collect($restaurant['modifier_groups'])->firstWhere('name', 'Add-ons');
    expect(array_column($addons['modifiers'], 'price_delta_cents', 'name')['Extra Egg'])->toBe(2500);

    $adobo = collect($restaurant['products'])->firstWhere('name', 'Chicken Adobo');
    expect($adobo['modifier_groups'])->toContain('Rice')->toContain('Add-ons');
    $garlicRice = collect($restaurant['products'])->firstWhere('name', 'Garlic Fried Rice');
    expect($garlicRice['modifier_groups'] ?? [])->not->toContain('Rice');
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/SeedDataTest.php`
Expected: FAIL — `missing .../database/seeders/data/grocery.json`

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Unit/SeedDataTest.php
git commit -m "Tests: pin the seed data contract (counts, EAN-13, anchors)"
```

---

### Task 3: grocery.json — 200 real Philippine items from Open Food Facts

**Files:**
- Create: `backend/database/seeders/data/grocery.json` (committed output)
- Scratchpad only (not committed): `<scratchpad>/gen-grocery/generate.sh`, `<scratchpad>/gen-grocery/curated.json`

**Interfaces:**
- Produces: `grocery.json` in the Task 2 schema — 200 variants total: 189 fetched real PH products (real EAN-13 barcodes) + 11 curated (3 anchors, 5 per-kg fresh-market items, 1 three-variant Coca-Cola product).

- [ ] **Step 1: Write the curated items file**

The internet lookup for these was verified at plan time (`ph.openfoodfacts.org` reports 8,691 PH products). Curated anchors use generated checksum-valid EAN-13s in the reserved-looking `4809990…` range so they can never collide with a fetched real barcode. Write `<scratchpad>/gen-grocery/curated.json` exactly:

```json
{
  "products": [
    {"name": "Nescafé Classic 100g", "description": null, "category": "Coffee & Breakfast",
     "variants": [{"name": "Default", "sku": "GRC-NESCAFE-100", "barcode": "4809990000016", "price_cents": 18500, "cost_cents": 14000, "track_inventory": true, "tax_exempt": false}]},
    {"name": "Lucky Me! Pancit Canton Original 60g", "description": null, "category": "Instant Noodles",
     "variants": [{"name": "Default", "sku": "GRC-LUCKYME-60", "barcode": "4809990000023", "price_cents": 1500, "cost_cents": 1100, "track_inventory": true, "tax_exempt": false}]},
    {"name": "Century Tuna Flakes in Oil 420g", "description": null, "category": "Canned Goods",
     "variants": [{"name": "Default", "sku": "GRC-TUNA-420", "barcode": "4809990000030", "price_cents": 25000, "cost_cents": 19000, "track_inventory": true, "tax_exempt": false}]},
    {"name": "Bangus (per kg)", "description": null, "category": "Fresh Market",
     "variants": [{"name": "Default", "sku": "GRC-BANGUS-KG", "barcode": "4809990000047", "price_cents": 22000, "cost_cents": 16500, "track_inventory": true, "tax_exempt": true}]},
    {"name": "Dinorado Rice (per kg)", "description": null, "category": "Fresh Market",
     "variants": [{"name": "Default", "sku": "GRC-RICE-KG", "barcode": "4809990000054", "price_cents": 6500, "cost_cents": 5200, "track_inventory": true, "tax_exempt": true}]},
    {"name": "Pork Liempo (per kg)", "description": null, "category": "Fresh Market",
     "variants": [{"name": "Default", "sku": "GRC-LIEMPO-KG", "barcode": "4809990000061", "price_cents": 38000, "cost_cents": 30000, "track_inventory": true, "tax_exempt": true}]},
    {"name": "Whole Chicken (per kg)", "description": null, "category": "Fresh Market",
     "variants": [{"name": "Default", "sku": "GRC-CHICKEN-KG", "barcode": "4809990000078", "price_cents": 21000, "cost_cents": 16000, "track_inventory": true, "tax_exempt": true}]},
    {"name": "Tilapia (per kg)", "description": null, "category": "Fresh Market",
     "variants": [{"name": "Default", "sku": "GRC-TILAPIA-KG", "barcode": "4809990000085", "price_cents": 16000, "cost_cents": 12000, "track_inventory": true, "tax_exempt": true}]},
    {"name": "Coca-Cola", "description": null, "category": "Beverages",
     "variants": [
       {"name": "Mismo 300ml", "sku": "GRC-COKE-300", "barcode": "4809990000092", "price_cents": 2000, "cost_cents": 1500, "track_inventory": true, "tax_exempt": false},
       {"name": "1L",         "sku": "GRC-COKE-1L",  "barcode": "4809990000108", "price_cents": 5500, "cost_cents": 4200, "track_inventory": true, "tax_exempt": false},
       {"name": "1.5L",       "sku": "GRC-COKE-1_5L","barcode": "4809990000115", "price_cents": 7500, "cost_cents": 5600, "track_inventory": true, "tax_exempt": false}
     ]}
  ]
}
```

- [ ] **Step 2: Write the generator script**

Write `<scratchpad>/gen-grocery/generate.sh`. It fetches ~500 popular real PH products (name, brand, barcode, category tags) from Open Food Facts, cleans them, maps categories, assigns deterministic peso prices from the barcode (OFF carries no PH prices), and merges the curated file:

```bash
#!/usr/bin/env bash
# One-shot generator for backend/database/seeders/data/grocery.json.
# Real PH product names/brands/EAN-13s from Open Food Facts; prices assigned
# deterministically from the barcode into per-category centavo ranges.
set -euo pipefail
cd "$(dirname "$0")"
REPO_ROOT=$(git -C "$OLDPWD" rev-parse --show-toplevel 2>/dev/null || git rev-parse --show-toplevel)
OUT="$REPO_ROOT/backend/database/seeders/data/grocery.json"
mkdir -p "$(dirname "$OUT")"

for page in 1 2 3 4 5; do
  curl -sf --max-time 60 -A "pos-dev-seed-generator/1.0" \
    "https://ph.openfoodfacts.org/api/v2/search?countries_tags_en=philippines&fields=code,product_name,brands,categories_tags_en&sort_by=unique_scans_n&page_size=100&page=$page" \
    > "page$page.json"
done

jq -s '
  def trimmed: gsub("^\\s+|\\s+$"; "");
  def catfor: . as $tags |
    if     any($tags[]?; test("(?i)noodle"))                                       then "Instant Noodles"
    elif   any($tags[]?; test("(?i)beverage|juice|soda|water|tea"))                then "Beverages"
    elif   any($tags[]?; test("(?i)snack|biscuit|confection|chocolat|cand|chips|crisps")) then "Snacks"
    elif   any($tags[]?; test("(?i)canned|tinned|preserved"))                      then "Canned Goods"
    elif   any($tags[]?; test("(?i)sauce|condiment|vinegar|seasoning|spice"))      then "Condiments & Sauces"
    elif   any($tags[]?; test("(?i)dair|milk|yogurt|cheese|frozen|ice cream"))     then "Dairy & Frozen"
    elif   any($tags[]?; test("(?i)coffee|cocoa|breakfast|cereal"))                then "Coffee & Breakfast"
    else "Pantry Staples" end;
  def rangefor: {
    "Instant Noodles":     {lo:  800, hi:  3500},
    "Beverages":           {lo: 2000, hi: 15000},
    "Snacks":              {lo: 1500, hi: 12000},
    "Canned Goods":        {lo: 2500, hi: 12000},
    "Condiments & Sauces": {lo: 2000, hi: 20000},
    "Dairy & Frozen":      {lo: 5000, hi: 30000},
    "Coffee & Breakfast":  {lo: 5000, hi: 40000},
    "Pantry Staples":      {lo: 2000, hi: 25000}
  }[.];

  [ .[].products[]
    | {code, name: ((.product_name // "") | trimmed), brand: ((.brands // "") | split(",")[0] | trimmed), tags: (.categories_tags_en // [])}
    | select(.code | test("^[0-9]{13}$"))
    | select(.name | test("^[ -~]{3,60}$"))
    | select(.name | test("(?i)nescafe classic|pancit canton|century tuna|coca.?cola") | not)
    | .name = (if .brand != "" and ((.name | ascii_downcase) | contains(.brand | ascii_downcase) | not)
               then .brand + " " + .name else .name end)
  ]
  | unique_by(.code) | unique_by(.name | ascii_downcase)
  | .[0:189]
  | if length != 189 then error("only \(length) usable products fetched — raise the page count") else . end
  | map(
      (.tags | catfor) as $cat
      | ($cat | rangefor) as $r
      | (((.code | tonumber) % ($r.hi - $r.lo)) + $r.lo) as $raw
      | ($raw - ($raw % 25)) as $price
      | {name, description: null, category: $cat,
         variants: [{name: "Default", sku: ("GRC-" + .code), barcode: .code,
                     price_cents: $price, cost_cents: (($price * 3 / 4) | floor),
                     track_inventory: true, tax_exempt: false}]}
    )
' page1.json page2.json page3.json page4.json page5.json > fetched.json

jq -n --slurpfile curated curated.json --slurpfile fetched fetched.json '
  ["Fresh Market", "Instant Noodles", "Canned Goods", "Beverages", "Snacks",
   "Condiments & Sauces", "Dairy & Frozen", "Coffee & Breakfast", "Pantry Staples"] as $order
  | ($curated[0].products + $fetched[0]) as $products
  | {categories: ([$products[].category] | unique
                  | map(. as $c | {name: $c, sort_order: (($order | index($c)) + 1)})
                  | sort_by(.sort_order)),
     modifier_groups: [],
     products: $products}
' > "$OUT"

echo "wrote $OUT: $(jq '[.products[].variants[]] | length' "$OUT") variants"
```

- [ ] **Step 3: Run the generator**

```bash
bash <scratchpad>/gen-grocery/generate.sh
```

Expected: `wrote .../grocery.json: 200 variants`. If Open Food Facts is unreachable or returns fewer than 189 usable products after filtering, raise the page loop to 8 pages; as a last resort (site down), use WebSearch for Philippine supermarket price lists and hand-curate the shortfall with generated `4809990…` barcodes — the validation test defines done either way.

- [ ] **Step 4: Run the validation test — grocery tests pass**

Run: `cd backend && ./vendor/bin/pest tests/Unit/SeedDataTest.php`
Expected: grocery count, EAN-13, and uniqueness tests PASS; restaurant/cafe tests still FAIL (files missing).

- [ ] **Step 5: Spot-check and commit**

Eyeball `jq '.products[:5]' backend/database/seeders/data/grocery.json` — names should read like real shelf products, prices like plausible centavo amounts.

```bash
git add backend/database/seeders/data/grocery.json
git commit -m "Seed data: 200 Philippine grocery items sourced from Open Food Facts"
```

---

### Task 4: restaurant.json — 30 Filipino menu items

**Files:**
- Create: `backend/database/seeders/data/restaurant.json`

**Interfaces:**
- Produces: `restaurant.json` per the Task 2 schema, with the `RST-ADOBO-CHK`/`RST-GARLIC-RICE`/`RST-HALOHALO` anchors and the Rice/Add-ons groups exactly as pinned in Global Constraints.

- [ ] **Step 1: Verify price realism online**

Use WebSearch for current Manila casual-restaurant prices (queries like `"Filipino restaurant menu prices Manila"`, `"chicken adobo price restaurant Manila"`). The baseline below was drafted from typical 2025–2026 Manila prices; adjust any non-anchor price that research shows off by more than ~30%. **Anchor prices (Global Constraints) stay fixed** — the tests and e2e math pin them.

- [ ] **Step 2: Write the file**

Create `backend/database/seeders/data/restaurant.json`. Categories: Appetizers (1), Mains (2), Noodles & Rice (3), Desserts (4), Beverages (5). Modifier groups:

```json
"modifier_groups": [
  {"name": "Rice", "min_select": 1, "max_select": 1, "modifiers": [
    {"name": "Plain Rice", "price_delta_cents": 0},
    {"name": "Garlic Rice", "price_delta_cents": 2000},
    {"name": "No Rice", "price_delta_cents": -1500}]},
  {"name": "Add-ons", "min_select": 0, "max_select": 3, "modifiers": [
    {"name": "Extra Egg", "price_delta_cents": 2500},
    {"name": "Chicharon Bits", "price_delta_cents": 3000},
    {"name": "Extra Sauce", "price_delta_cents": 1500}]},
  {"name": "Serving Size", "min_select": 1, "max_select": 1, "modifiers": [
    {"name": "Solo", "price_delta_cents": 0},
    {"name": "Family", "price_delta_cents": 15000}]},
  {"name": "Spice Level", "min_select": 1, "max_select": 1, "modifiers": [
    {"name": "Mild", "price_delta_cents": 0},
    {"name": "Regular", "price_delta_cents": 0},
    {"name": "Extra Spicy", "price_delta_cents": 0}]}
]
```

The 30 products — every variant: `{"name": "Default", "sku": …, "barcode": null, "cost_cents": null, "track_inventory": false, "tax_exempt": false}` with the price shown. Each product gets a one-sentence real description of the dish (write them; e.g. Chicken Adobo: "Chicken braised in soy sauce, vinegar, garlic, and bay leaves — the house classic."). Groups per row:

| Product | SKU | price_cents | Category | modifier_groups |
|---|---|---|---|---|
| Lumpiang Shanghai | RST-LUMPIA-SH | 18000 | Appetizers | Add-ons |
| Calamares | RST-CALAMARES | 22000 | Appetizers | — |
| Tokwa't Baboy | RST-TOKWAT | 16000 | Appetizers | — |
| Chicharon Bulaklak | RST-CHICHARON | 24000 | Appetizers | — |
| Cheese Sticks | RST-CHEESESTIX | 12000 | Appetizers | — |
| Chicken Adobo | RST-ADOBO-CHK | 22000 | Mains | Rice, Add-ons |
| Pork Sinigang | RST-SINIGANG-PRK | 28000 | Mains | Rice, Add-ons |
| Kare-Kare | RST-KAREKARE | 32000 | Mains | Rice, Add-ons |
| Lechon Kawali | RST-LECHON-KAW | 28000 | Mains | Rice, Add-ons |
| Sizzling Sisig | RST-SISIG | 24000 | Mains | Rice, Add-ons, Spice Level |
| Grilled Liempo | RST-LIEMPO | 26000 | Mains | Rice, Add-ons |
| Chicken Inasal | RST-INASAL | 21000 | Mains | Rice, Add-ons |
| Beef Caldereta | RST-CALDERETA | 30000 | Mains | Rice, Add-ons, Spice Level |
| Crispy Pata | RST-CRISPYPATA | 55000 | Mains | Rice, Add-ons |
| Bicol Express | RST-BICOLEXP | 24000 | Mains | Rice, Add-ons, Spice Level |
| Pancit Canton | RST-PANCIT-CTN | 18000 | Noodles & Rice | Serving Size, Add-ons |
| Pancit Bihon | RST-PANCIT-BHN | 17000 | Noodles & Rice | Serving Size, Add-ons |
| Garlic Fried Rice | RST-GARLIC-RICE | 6000 | Noodles & Rice | Add-ons |
| Plain Rice | RST-PLAIN-RICE | 3000 | Noodles & Rice | — |
| Arroz Caldo | RST-ARROZCALDO | 15000 | Noodles & Rice | Add-ons |
| Bagoong Fried Rice | RST-BAGOONG-RICE | 12000 | Noodles & Rice | Add-ons |
| Halo-Halo | RST-HALOHALO | 15000 | Desserts | — |
| Leche Flan | RST-LECHEFLAN | 9000 | Desserts | — |
| Turon | RST-TURON | 8000 | Desserts | — |
| Buko Pandan | RST-BUKOPANDAN | 9000 | Desserts | — |
| Sago't Gulaman | RST-SAGO | 7000 | Beverages | — |
| Calamansi Juice | RST-CALAMANSI | 8000 | Beverages | — |
| Buko Juice | RST-BUKO | 9000 | Beverages | — |
| Canned Soda | RST-SODA | 6000 | Beverages | — |
| Bottled Water | RST-WATER | 3000 | Beverages | — |

`RST-GARLIC-RICE` and `RST-HALOHALO` must NOT carry a required group — the lunch-service e2e adds them with no modifiers.

- [ ] **Step 3: Run the validation test**

Run: `cd backend && ./vendor/bin/pest tests/Unit/SeedDataTest.php`
Expected: restaurant tests PASS; only cafe still FAILS.

- [ ] **Step 4: Commit**

```bash
git add backend/database/seeders/data/restaurant.json
git commit -m "Seed data: 30 Filipino restaurant menu items with modifiers"
```

---

### Task 5: cafe.json — 20 cafe items

**Files:**
- Create: `backend/database/seeders/data/cafe.json`

- [ ] **Step 1: Verify price realism online**

WebSearch Manila cafe menus (`"coffee shop menu prices Manila"`, `"spanish latte price Manila cafe"`); same rule as Task 4 — adjust the baseline where research disagrees materially. No e2e anchors in this file; only the count (20) and schema are pinned.

- [ ] **Step 2: Write the file**

Create `backend/database/seeders/data/cafe.json`. Categories: Coffee (1), Non-Coffee (2), Pastries (3), Sandwiches (4). Modifier groups:

```json
"modifier_groups": [
  {"name": "Milk", "min_select": 1, "max_select": 1, "modifiers": [
    {"name": "Fresh Milk", "price_delta_cents": 0},
    {"name": "Oat Milk", "price_delta_cents": 3000},
    {"name": "Soy Milk", "price_delta_cents": 2500},
    {"name": "No Milk", "price_delta_cents": -1000}]},
  {"name": "Cup Size", "min_select": 1, "max_select": 1, "modifiers": [
    {"name": "12 oz", "price_delta_cents": 0},
    {"name": "16 oz", "price_delta_cents": 3000}]},
  {"name": "Extras", "min_select": 0, "max_select": 3, "modifiers": [
    {"name": "Extra Shot", "price_delta_cents": 4000},
    {"name": "Vanilla Syrup", "price_delta_cents": 2500},
    {"name": "Whipped Cream", "price_delta_cents": 2000}]}
]
```

The 20 products (variants shaped as in Task 4; descriptions one sentence each):

| Product | SKU | price_cents | Category | modifier_groups |
|---|---|---|---|---|
| Kapeng Barako | CAF-BARAKO | 12000 | Coffee | Cup Size, Extras |
| Americano | CAF-AMERICANO | 13000 | Coffee | Cup Size, Extras |
| Cappuccino | CAF-CAPPUCCINO | 16000 | Coffee | Milk, Cup Size, Extras |
| Café Latte | CAF-LATTE | 16500 | Coffee | Milk, Cup Size, Extras |
| Spanish Latte | CAF-SPANISH | 18000 | Coffee | Milk, Cup Size, Extras |
| Caramel Macchiato | CAF-CARAMEL | 19000 | Coffee | Milk, Cup Size, Extras |
| Mocha | CAF-MOCHA | 18500 | Coffee | Milk, Cup Size, Extras |
| Cold Brew | CAF-COLDBREW | 17000 | Coffee | Cup Size, Extras |
| Hot Chocolate | CAF-HOTCHOC | 15000 | Non-Coffee | Milk, Cup Size |
| Matcha Latte | CAF-MATCHA | 19000 | Non-Coffee | Milk, Cup Size, Extras |
| Iced Tea | CAF-ICEDTEA | 10000 | Non-Coffee | Cup Size |
| Fresh Mango Shake | CAF-MANGO | 15000 | Non-Coffee | Cup Size |
| Ensaymada | CAF-ENSAYMADA | 9000 | Pastries | — |
| Pandesal (3 pcs) | CAF-PANDESAL | 5000 | Pastries | — |
| Ube Cheese Pandesal | CAF-UBEPANDESAL | 7500 | Pastries | — |
| Banana Bread Slice | CAF-BANANA | 9500 | Pastries | — |
| Egg Pie Slice | CAF-EGGPIE | 8500 | Pastries | — |
| Chicken Pesto Sandwich | CAF-PESTO | 18500 | Sandwiches | — |
| Ham & Cheese Croissant | CAF-HAMCHEESE | 16000 | Sandwiches | — |
| Tuna Melt | CAF-TUNAMELT | 17500 | Sandwiches | — |

- [ ] **Step 3: Run the full validation suite — all green**

Run: `cd backend && ./vendor/bin/pest tests/Unit/SeedDataTest.php`
Expected: PASS, all tests.

- [ ] **Step 4: Commit**

```bash
git add backend/database/seeders/data/cafe.json
git commit -m "Seed data: 20 Manila cafe drinks and pastries with modifiers"
```

---

### Task 6: `CatalogSeeder` base + `GrocerySeeder`

**Files:**
- Create: `backend/database/seeders/CatalogSeeder.php`
- Create: `backend/database/seeders/GrocerySeeder.php`
- Test: `backend/tests/Feature/Seed/CatalogSeedersTest.php`

**Interfaces:**
- Consumes: the JSON schema (Task 2), `RoleProvisioner` (idempotent — "Safe to re-run" per its docblock), `StockLedger::receive(string $variantId, string $locationId, Quantity $qty, ?string $userId, ?string $note)`.
- Produces: `CatalogSeeder::seed(): array{location: \App\Models\Location, tokens: array<array{string, string}>}` — tokens are `[label, plaintext]` pairs. Tax rates `TaxRate` "VAT 12%" (120_000 micros) and "VAT Exempt" (0), created idempotently via `firstOrCreate`. Subclass contract: `locationAttributes(): array`, `registerModes(): array<string, string>`, `dataFile(): string`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Seed/CatalogSeedersTest.php` (Feature tests get `RefreshDatabase` + real Postgres from `tests/Pest.php`):

```php
<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\TaxRate;
use Database\Seeders\GrocerySeeder;
use Illuminate\Support\Facades\DB;

it('seeds the grocery catalog: Manila location, retail tills, 200 items, ledger stock', function (): void {
    $result = (new GrocerySeeder)->seed();
    $location = $result['location'];

    expect($location->code)->toBe('GRC');
    expect($location->timezone)->toBe('Asia/Manila');
    expect($location->prices_include_tax)->toBeTrue();

    expect(Register::query()->where('location_id', $location->id)->orderBy('name')->pluck('mode')->all())
        ->toBe(['retail', 'retail']);
    expect($result['tokens'])->toHaveCount(2);
    expect($result['tokens'][0][0])->toBe('GRC / Till 1');

    expect(ProductVariant::query()->count())->toBe(200);

    $anchor = ProductVariant::query()->where('sku', 'GRC-NESCAFE-100')->firstOrFail();
    expect($anchor->barcode)->toBe('4809990000016');
    expect($anchor->price_cents)->toBe(18500);
    expect($anchor->taxRate->name)->toBe('VAT 12%');
    expect(TaxRate::query()->where('name', 'VAT 12%')->firstOrFail()->rate_micros)->toBe(120_000);

    $exempt = ProductVariant::query()->where('sku', 'GRC-BANGUS-KG')->firstOrFail();
    expect($exempt->taxRate->name)->toBe('VAT Exempt');
    expect($exempt->taxRate->rate_micros)->toBe(0);

    // Stock went through the ledger, so stock_levels agrees with the movement sum.
    $level = DB::table('stock_levels')
        ->where('variant_id', $anchor->id)->where('location_id', $location->id)->first();
    expect((string) $level->qty)->toBe('20.000');
    expect(DB::table('stock_movements')->count())->toBe(200);
});
```

If `ProductVariant` has no `taxRate` relation, assert via `TaxRate::find($anchor->tax_rate_id)->name` instead — check the model first.

- [ ] **Step 2: Run it to make sure it fails**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Seed/CatalogSeedersTest.php`
Expected: FAIL — `Class "Database\Seeders\GrocerySeeder" not found`

- [ ] **Step 3: Write the base class**

Create `backend/database/seeders/CatalogSeeder.php`:

```php
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
```

- [ ] **Step 4: Write GrocerySeeder**

Create `backend/database/seeders/GrocerySeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

/** A Manila neighborhood grocery: barcoded shelf goods, everything tracked. */
class GrocerySeeder extends CatalogSeeder
{
    protected function locationAttributes(): array
    {
        return [
            'name' => 'Manila Grocery',
            'code' => 'GRC',
            'receipt_header' => 'Manila Grocery',
            'receipt_footer' => 'Salamat po! VAT included.',
        ];
    }

    protected function registerModes(): array
    {
        return ['Till 1' => 'retail', 'Till 2' => 'retail'];
    }

    protected function dataFile(): string
    {
        return 'grocery.json';
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Seed/CatalogSeedersTest.php`
Expected: PASS. (If `Location` mass-assignment rejects `receipt_header`/`receipt_footer`, check how the current `DatabaseSeeder` passes them — it does, so they are fillable.)

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/CatalogSeeder.php backend/database/seeders/GrocerySeeder.php backend/tests/Feature/Seed/CatalogSeedersTest.php
git commit -m "Seeder: JSON-driven CatalogSeeder base and the Manila grocery catalog"
```

---

### Task 7: `RestaurantSeeder` + `CafeSeeder`

**Files:**
- Create: `backend/database/seeders/RestaurantSeeder.php`
- Create: `backend/database/seeders/CafeSeeder.php`
- Modify: `backend/tests/Feature/Seed/CatalogSeedersTest.php` (append)

**Interfaces:**
- Consumes: `CatalogSeeder` (Task 6), `restaurant.json`/`cafe.json` (Tasks 4–5).
- Produces: locations `RST` "Manila Restaurant" and `CAF` "Manila Cafe", both with `Till 1`/`Till 2` in `food` mode; all their variants untracked (no stock rows).

- [ ] **Step 1: Append the failing tests**

Append to `backend/tests/Feature/Seed/CatalogSeedersTest.php` (add `use App\Models\Product;` and `use Database\Seeders\{CafeSeeder, RestaurantSeeder};` to the imports):

```php
it('seeds the restaurant catalog: food tills, 30 dishes, modifier wiring, no stock', function (): void {
    $result = (new RestaurantSeeder)->seed();
    $location = $result['location'];

    expect($location->code)->toBe('RST');
    expect(Register::query()->where('location_id', $location->id)->pluck('mode')->all())
        ->toBe(['food', 'food']);
    expect(Product::query()->count())->toBe(30);
    expect(DB::table('stock_movements')->count())->toBe(0);

    $adobo = Product::query()->where('name', 'Chicken Adobo')->firstOrFail();
    $groupNames = $adobo->modifierGroups()->orderBy('product_modifier_groups.position')->pluck('name')->all();
    expect($groupNames)->toBe(['Rice', 'Add-ons']);

    $rice = $adobo->modifierGroups()->where('name', 'Rice')->firstOrFail();
    expect($rice->min_select)->toBe(1);
    $deltas = $rice->modifiers()->pluck('price_delta_cents', 'name')->all();
    expect($deltas['No Rice'])->toBe(-1500);   // signed delta survives the pipeline
    expect($deltas['Garlic Rice'])->toBe(2000);
});

it('seeds the cafe catalog: food tills and 20 items', function (): void {
    $result = (new CafeSeeder)->seed();

    expect($result['location']->code)->toBe('CAF');
    expect(Register::query()->pluck('mode')->unique()->all())->toBe(['food']);
    expect(Product::query()->count())->toBe(20);

    $latte = Product::query()->where('name', 'Café Latte')->firstOrFail();
    expect($latte->modifierGroups()->pluck('name')->all())->toContain('Milk');
});
```

If the modifier-group pivot table is not named `product_modifier_groups`, check the migration (`2026_07_16_000400_create_catalog_tables.php`) for the actual name and adjust the `orderBy`.

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Seed/CatalogSeedersTest.php`
Expected: the two new tests FAIL — `Class "Database\Seeders\RestaurantSeeder" not found`.

- [ ] **Step 3: Write both seeders**

`backend/database/seeders/RestaurantSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

/** A Manila turo-turo restaurant: open tabs, coursing, rice-and-add-on modifiers. */
class RestaurantSeeder extends CatalogSeeder
{
    protected function locationAttributes(): array
    {
        return [
            'name' => 'Manila Restaurant',
            'code' => 'RST',
            'receipt_header' => 'Manila Restaurant',
            'receipt_footer' => 'Salamat po! VAT included.',
        ];
    }

    protected function registerModes(): array
    {
        return ['Till 1' => 'food', 'Till 2' => 'food'];
    }

    protected function dataFile(): string
    {
        return 'restaurant.json';
    }
}
```

`backend/database/seeders/CafeSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

/** A Manila cafe: espresso drinks with milk/size/extras modifiers, counter pastries. */
class CafeSeeder extends CatalogSeeder
{
    protected function locationAttributes(): array
    {
        return [
            'name' => 'Manila Cafe',
            'code' => 'CAF',
            'receipt_header' => 'Manila Cafe',
            'receipt_footer' => 'Salamat po! VAT included.',
        ];
    }

    protected function registerModes(): array
    {
        return ['Till 1' => 'food', 'Till 2' => 'food'];
    }

    protected function dataFile(): string
    {
        return 'cafe.json';
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Seed/CatalogSeedersTest.php`
Expected: PASS (all four tests in the file).

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/RestaurantSeeder.php backend/database/seeders/CafeSeeder.php backend/tests/Feature/Seed/CatalogSeedersTest.php
git commit -m "Seeder: Manila restaurant and cafe catalogs"
```

---

### Task 8: Rewrite `DatabaseSeeder` — env-selected catalogs, staff across locations

**Files:**
- Modify: `backend/database/seeders/DatabaseSeeder.php` (full rewrite)
- Test: `backend/tests/Feature/Seed/DatabaseSeederTest.php` (create)

**Interfaces:**
- Consumes: `config('pos.seed_catalogs')` (Task 1), `CatalogSeeder::seed()` (Task 6).
- Produces: the printed token table rows now read `GRC / Till 1`, `RST / Till 2`, etc. — Task 9's Makefile greps depend on this exact `| CODE / Till N |` format. Staff: Alice cashier and Bob supervisor at every enabled location; Maria cashier at the first, supervisor at the second when it exists; Priya global admin. Discounts: "10% off" and "₱50 off" (fixed 5000).

- [ ] **Step 1: Write the failing tests**

Create `backend/tests/Feature/Seed/DatabaseSeederTest.php`:

```php
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
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Seed/DatabaseSeederTest.php`
Expected: FAIL — the current seeder still builds Downtown/London (`pluck('code')` returns `['DT', 'LDN']`).

- [ ] **Step 3: Rewrite DatabaseSeeder**

Replace the full contents of `backend/database/seeders/DatabaseSeeder.php`:

```php
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
        $this->command?->table(['Email', 'Password'], [['admin@pos.test', 'admin-dev-password']]);
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
            'password_hash' => Hash::make('admin-dev-password'),
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
```

- [ ] **Step 4: Run the new tests, then the whole backend suite**

Run: `cd backend && ./vendor/bin/pest tests/Feature/Seed/`
Expected: PASS (all).

Run: `cd backend && ./vendor/bin/pest`
Expected: PASS — the previous 476 plus the new Seed tests. Nothing else references Downtown/London seed data (tests build their own fixtures via factories); if something unexpectedly red, fix forward here before committing.

- [ ] **Step 5: Commit**

```bash
git add backend/database/seeders/DatabaseSeeder.php backend/tests/Feature/Seed/DatabaseSeederTest.php
git commit -m "Seeder: env-selected Manila catalogs replace Downtown/London"
```

---

### Task 9: Makefile seed/e2e plumbing + rewrite `e2e-retail-day.sh`

**Files:**
- Modify: `Makefile` (`seed` target ~line 65, `e2e` target ~lines 110–137, comment block ~lines 70–108)
- Modify: `scripts/e2e-retail-day.sh` (full replacement below)

**Interfaces:**
- Consumes: seeder token table rows `| GRC / Till 1 | <token> |` etc. (Task 8).
- Produces: `make seed` honors `POS_SEED_CATALOGS` (make variable or environment, default `grocery`); `make e2e` seeds all three catalogs for the script runs. VAT-inclusive retail-day math: void → 18500; 10% off → discount 1850, total 16650; tendered 20000 → change 3350; card sale 25000; refund 16650.

- [ ] **Step 1: Makefile — parameterize seed**

Near the top of the `Makefile` (after the `.PHONY` line), add:

```make
# Which demo catalogs `make seed` builds — comma-separated subset of
# grocery,restaurant,cafe. Mirrors config/pos.php's default.
POS_SEED_CATALOGS ?= grocery
```

Change the `seed` recipe to pass it through explicitly (compose `exec` does not inherit the host shell environment):

```make
seed: wait-api ## Fresh migrate + seed (prints dev PINs and device tokens; POS_SEED_CATALOGS=grocery,restaurant,cafe for all three catalogs)
	$(COMPOSE_DEV) exec --user pos -e POS_SEED_CATALOGS=$(POS_SEED_CATALOGS) api php artisan migrate:fresh --seed
```

- [ ] **Step 2: Makefile — retarget the e2e token extraction**

In the `e2e` target: the first `$(MAKE) seed` becomes `$(MAKE) seed POS_SEED_CATALOGS=grocery,restaurant,cafe` (redirect unchanged). Replace the two grep/sed extraction pairs and the export lines so retail-day runs on the grocery till and lunch-service on the restaurant tills:

```make
	@grep '| GRC / Till 1 ' $(E2E_TMP)/pos-seed-out.txt | sed -E 's/^\| *GRC \/ Till 1 *\| *(.*) *\|$$/\1/' | sed -E 's/ +$$//' > $(E2E_TMP)/pos-e2e-grc1.txt
	@grep '| RST / Till 1 ' $(E2E_TMP)/pos-seed-out.txt | sed -E 's/^\| *RST \/ Till 1 *\| *(.*) *\|$$/\1/' | sed -E 's/ +$$//' > $(E2E_TMP)/pos-e2e-rst1.txt
	@grep '| RST / Till 2 ' $(E2E_TMP)/pos-seed-out.txt | sed -E 's/^\| *RST \/ Till 2 *\| *(.*) *\|$$/\1/' | sed -E 's/ +$$//' > $(E2E_TMP)/pos-e2e-rst2.txt
	@test -s $(E2E_TMP)/pos-e2e-grc1.txt && test -s $(E2E_TMP)/pos-e2e-rst1.txt && test -s $(E2E_TMP)/pos-e2e-rst2.txt || { echo "could not extract GRC/RST device tokens — seeder's printed table format may have changed"; exit 1; }
	@echo "extracted: GRC/Till1=$$(cut -c1-8 $(E2E_TMP)/pos-e2e-grc1.txt)... RST/Till1=$$(cut -c1-8 $(E2E_TMP)/pos-e2e-rst1.txt)... RST/Till2=$$(cut -c1-8 $(E2E_TMP)/pos-e2e-rst2.txt)..."
	POS_DEVICE_TOKEN=$$(cat $(E2E_TMP)/pos-e2e-grc1.txt) bash scripts/e2e-retail-day.sh
	POS_DEVICE_TOKEN=$$(cat $(E2E_TMP)/pos-e2e-rst2.txt) POS_DEVICE_TOKEN_2=$$(cat $(E2E_TMP)/pos-e2e-rst1.txt) bash scripts/e2e-lunch-service.sh
```

The second reseed (admin-day precondition) only needs the grocery catalog; make that explicit and retarget its extraction:

```make
	@$(MAKE) seed POS_SEED_CATALOGS=grocery > $(E2E_TMP)/pos-seed-out2.txt 2>&1 || { cat $(E2E_TMP)/pos-seed-out2.txt; exit 1; }
	@cat $(E2E_TMP)/pos-seed-out2.txt
	@grep '| GRC / Till 1 ' $(E2E_TMP)/pos-seed-out2.txt | sed -E 's/^\| *GRC \/ Till 1 *\| *(.*) *\|$$/\1/' | sed -E 's/ +$$//' > $(E2E_TMP)/pos-e2e-grc1b.txt
	@test -s $(E2E_TMP)/pos-e2e-grc1b.txt || { echo "could not extract the GRC / Till 1 device token on the second reseed"; exit 1; }
	POS_ADMIN_EMAIL=admin@pos.test POS_ADMIN_PASSWORD=admin-dev-password POS_DEVICE_TOKEN=$$(cat $(E2E_TMP)/pos-e2e-grc1b.txt) POS_E2E_PIN=9876 bash scripts/e2e-admin-day.sh
```

Update the two long comment blocks in place: every `DT / Till N` mention becomes the GRC/RST labels above; the "Only Downtown's tokens are needed... Bob/Alice, who only hold roles at Downtown" sentence becomes "Alice and Bob hold roles at every seeded location; retail-day and admin-day run on the grocery till (GRC), lunch-service on the restaurant's two tills (RST)." In the admin-day comment, "Downtown day-gross of exactly 510c" → "Manila Grocery (GRC) day-gross of exactly 510c", and the last sentence about ordering ("admin-day flips Till 1 to food mode... e2e-lunch-service.sh depends on still being retail-mode") becomes: "admin-day flips GRC Till 1 to food mode and reissues its token mid-script, so it must stay last; the second reseed (grocery-only — that's all admin-day touches) restores its fresh-seed precondition."

- [ ] **Step 3: Rewrite e2e-retail-day.sh**

Replace the full contents of `scripts/e2e-retail-day.sh`:

```bash
#!/bin/bash
#
# The M4 'retail bad day' — the milestone's end-to-end proof, runnable against a
# freshly seeded stack (grocery catalog), at the Manila Grocery (GRC): void, discount,
# cash change, card sale, refund with restock, payout, Z-report, zero-variance close.
# GRC is prices_include_tax=true, so totals ARE the shelf prices — VAT is derived
# inside them, never added on top. Set DEVICE to the seeder-printed GRC / Till 1 token.
#
set -e
API=http://127.0.0.1:8000/api/v1
DEVICE="${POS_DEVICE_TOKEN:?set POS_DEVICE_TOKEN to the GRC / Till 1 device token printed by php artisan migrate:fresh --seed}"
D="Authorization: Bearer $DEVICE"
J='Content-Type: application/json'

STAFF=$(curl -sf -X POST $API/staff/login -H "$D" -H "$J" -d '{"pin":"2222"}' | jq -r .data.staff_token)
S="X-Staff-Token: $STAFF"
echo "1. Bob (supervisor) logged in"

SHIFT_ID=$(curl -sf -X POST $API/shifts/open -H "$D" -H "$S" -H "$J" -d '{"opening_float_cents":20000}' | jq -r .data.shift.id)
echo "2. shift open, float 20000"

DISC_ID=$(curl -sf $API/catalog -H "$D" | jq -r '.data.discounts[] | select(.name=="10% off") | .id')
VN=$(curl -sf "$API/catalog/lookup?barcode=4809990000016" -H "$D" | jq -r .data.variant.id)   # Nescafé Classic 100g, 18500
VP=$(curl -sf "$API/catalog/lookup?barcode=4809990000023" -H "$D" | jq -r .data.variant.id)   # Lucky Me! Pancit Canton, 1500
VT=$(curl -sf "$API/catalog/lookup?barcode=4809990000030" -H "$D" | jq -r .data.variant.id)   # Century Tuna 420g, 25000

# Sale A: coffee + noodles, void the noodles, 10% off, cash
OID=$(curl -sf -X POST $API/orders -H "$D" -H "$S" -H "$J" -d '{}' | jq -r .data.order.id)
R=$(curl -sf -X POST $API/orders/$OID/lines -H "$D" -H "$S" -H 'If-Match: 0' -H "$J" -d "{\"variant_id\":\"$VN\",\"qty\":\"1\"}")
L1=$(echo $R | jq -r .data.line.id)
R=$(curl -sf -X POST $API/orders/$OID/lines -H "$D" -H "$S" -H 'If-Match: 1' -H "$J" -d "{\"variant_id\":\"$VP\",\"qty\":\"1\"}")
L2=$(echo $R | jq -r .data.line.id)
echo "3. sale A: coffee + noodles, total=$(echo $R | jq .data.order.total_cents) (expect 20000)"

R=$(curl -sf -X DELETE $API/orders/$OID/lines/$L2 -H "$D" -H "$S" -H 'If-Match: 2' -H "$J" -d '{"reason":"mis-scan"}')
echo "4. line voided: total=$(echo $R | jq .data.order.total_cents) (expect 18500)"

R=$(curl -sf -X POST $API/orders/$OID/discounts -H "$D" -H "$S" -H 'If-Match: 3' -H "$J" -d "{\"discount_id\":\"$DISC_ID\",\"order_line_id\":null,\"reason\":\"manager comp\"}")
ODISC=$(echo $R | jq .data.order.discount_cents); TOTAL_A=$(echo $R | jq .data.order.total_cents)
echo "5. 10% off applied: discount=$ODISC total=$TOTAL_A (expect 1850 / 16650); rows=$(echo $R | jq '.data.order.discounts | length')"

PAY=$(curl -sf -X POST $API/orders/$OID/payments -H "$D" -H "$S" -H 'If-Match: 4' -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"driver\":\"cash\",\"amount_cents\":$TOTAL_A,\"tendered_cents\":20000}")
NUM_A=$(echo $PAY | jq -r .data.order.number)
echo "6. cash paid: change=$(echo $PAY | jq .data.payment.change_cents) (expect 3350) status=$(echo $PAY | jq -r .data.order.status)"

# Sale B: card
OID2=$(curl -sf -X POST $API/orders -H "$D" -H "$S" -H "$J" -d '{}' | jq -r .data.order.id)
R=$(curl -sf -X POST $API/orders/$OID2/lines -H "$D" -H "$S" -H 'If-Match: 0' -H "$J" -d "{\"variant_id\":\"$VT\",\"qty\":\"1\"}")
PAY2=$(curl -sf -X POST $API/orders/$OID2/payments -H "$D" -H "$S" -H 'If-Match: 1' -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"driver\":\"external_card\",\"amount_cents\":$(echo $R | jq .data.order.total_cents),\"reference\":\"auth 004321\"}")
echo "7. card sale: $(echo $PAY2 | jq .data.payment.amount_cents)c (expect 25000) ref=$(echo $PAY2 | jq -r .data.payment.reference) change=$(echo $PAY2 | jq .data.payment.change_cents) (expect null)"

# Refund sale A (find by number, then refund line 1 with restock)
FOUND=$(curl -sf "$API/orders?number=$NUM_A" -H "$D" -H "$S" | jq '.data.orders | length')
REF=$(curl -sf -X POST $API/refunds -H "$D" -H "$S" -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"original_order_id\":\"$OID\",\"driver\":\"cash\",\"reason\":\"faulty\",\"lines\":[{\"original_order_line_id\":\"$L1\",\"qty\":\"1\",\"restock\":true}]}")
echo "8. lookup found=$FOUND; refund amount=$(echo $REF | jq .data.refund.amount_cents) (expect 16650 = the discounted, VAT-inclusive line)"

curl -sf -X POST $API/shifts/$SHIFT_ID/cash-movements -H "$D" -H "$S" -H "$J" -d '{"kind":"payout","amount_cents":300,"reason":"window cleaner"}' > /dev/null
echo "9. payout 300 recorded"

Z=$(curl -sf "$API/reports/z?shift_id=$SHIFT_ID" -H "$D" -H "$S")
echo "10. Z: sales=$(echo $Z | jq -c .data.sales_by_driver) refunds=$(echo $Z | jq -c .data.refunds_by_driver) payout=$(echo $Z | jq .data.movements.payout) orders_closed=$(echo $Z | jq .data.orders_closed) expected=$(echo $Z | jq .data.expected_cash_cents)"

EXPECTED=$(echo $Z | jq .data.expected_cash_cents)
CLOSE=$(curl -sf -X POST $API/shifts/$SHIFT_ID/close -H "$D" -H "$S" -H "Idempotency-Key: $(uuidgen)" -H "$J" -d "{\"counted_cash_cents\":$EXPECTED}")
echo "11. closed: variance=$(echo $CLOSE | jq .data.variance_cents) (expect 0)"
```

- [ ] **Step 4: Verify against the running stack**

```bash
make dev            # if not already up
make seed POS_SEED_CATALOGS=grocery,restaurant,cafe
```

Copy the printed `GRC / Till 1` token, then:

```bash
POS_DEVICE_TOKEN='<GRC till 1 token>' bash scripts/e2e-retail-day.sh
```

Expected: all 11 steps print with the expected numbers (20000 / 18500 / 1850 / 16650 / 3350 / 25000 / 16650 / variance 0) and exit 0. If a printed total disagrees, trust the server: re-derive the expectation from the pricing rules (VAT-inclusive: totals are shelf prices; discount = `Money::fraction`(10%)) before touching data.

- [ ] **Step 5: Commit**

```bash
git add Makefile scripts/e2e-retail-day.sh
git commit -m "e2e: retail day re-anchored on the Manila grocery; make seed learns POS_SEED_CATALOGS"
```

---

### Task 10: Rewrite `e2e-lunch-service.sh` for the Manila restaurant

**Files:**
- Modify: `scripts/e2e-lunch-service.sh`

**Interfaces:**
- Consumes: RST tills (both `food`), anchors `RST-ADOBO-CHK` / `RST-GARLIC-RICE` / `RST-HALOHALO`, modifiers "Garlic Rice" / "Plain Rice" / "Extra Egg" (Tasks 4, 7); tokens `POS_DEVICE_TOKEN` = RST Till 2, `POS_DEVICE_TOKEN_2` = RST Till 1 (Task 9's Makefile).

The flows (tabs, repeated modifier, coursing, qty bump, 3-way split, transfer, variance approval) are untouched — only anchors, modes, and copy change. Apply these exact edits:

- [ ] **Step 1: Header comment and token prompts**

Old → new, in the header comment: `Set POS_DEVICE_TOKEN (Till 2, food) and POS_DEVICE_TOKEN_2 (Till 1) first` → `Set POS_DEVICE_TOKEN (RST / Till 2) and POS_DEVICE_TOKEN_2 (RST / Till 1) first — the Manila Restaurant's two food-mode tills`. And the two `:?` messages:

```bash
DEVICE="${POS_DEVICE_TOKEN:?set POS_DEVICE_TOKEN to the RST / Till 2 device token, printed by php artisan migrate:fresh --seed}"
DEVICE2="${POS_DEVICE_TOKEN_2:?set POS_DEVICE_TOKEN_2 to the RST / Till 1 device token, printed by php artisan migrate:fresh --seed}"
```

- [ ] **Step 2: Till 1 is food mode now**

```bash
# old
[ "$(echo "$LOGIN_B1" | jq -r .data.register.mode)" = "retail" ] || fail "Till 1 is not in retail mode"
echo "2. Bob (supervisor) logged in at Till 1 (retail mode, $TILL1)"
# new
[ "$(echo "$LOGIN_B1" | jq -r .data.register.mode)" = "food" ] || fail "Till 1 is not in food mode"
echo "2. Bob (supervisor) logged in at Till 1 (food mode, $TILL1)"
```

- [ ] **Step 3: Catalog anchors**

```bash
# old block
LATTE=$(echo "$CATALOG" | jq -r '.data.variants[] | select(.sku=="LATTE") | .id')
CHEDDAR=$(echo "$CATALOG" | jq -r '.data.variants[] | select(.sku=="CHEESE-KG") | .id')
TSHIRT_M=$(echo "$CATALOG" | jq -r '.data.variants[] | select(.sku=="TSHIRT-BLUE-M") | .id')
OAT=$(echo "$CATALOG" | jq -r '.data.modifiers[] | select(.name=="Oat") | .id')
WHOLE=$(echo "$CATALOG" | jq -r '.data.modifiers[] | select(.name=="Whole") | .id')
EXTRA_SHOT=$(echo "$CATALOG" | jq -r '.data.modifiers[] | select(.name=="Extra shot") | .id')
echo "5. catalog resolved: latte, cheddar, t-shirt/M, oat, whole, extra shot"
# new block
ADOBO=$(echo "$CATALOG" | jq -r '.data.variants[] | select(.sku=="RST-ADOBO-CHK") | .id')
FRIED_RICE=$(echo "$CATALOG" | jq -r '.data.variants[] | select(.sku=="RST-GARLIC-RICE") | .id')
HALO=$(echo "$CATALOG" | jq -r '.data.variants[] | select(.sku=="RST-HALOHALO") | .id')
GARLIC_RICE=$(echo "$CATALOG" | jq -r '.data.modifiers[] | select(.name=="Garlic Rice") | .id')
PLAIN_RICE=$(echo "$CATALOG" | jq -r '.data.modifiers[] | select(.name=="Plain Rice") | .id')
EXTRA_EGG=$(echo "$CATALOG" | jq -r '.data.modifiers[] | select(.name=="Extra Egg") | .id')
echo "5. catalog resolved: adobo, garlic fried rice, halo-halo, garlic rice, plain rice, extra egg"
```

- [ ] **Step 4: Variable renames everywhere below**

Mechanical, whole-file (word-boundary) renames: `$LATTE`→`$ADOBO` (but the *string* `select(.sku=="LATTE")` in the split/receipt jq becomes `select(.sku=="RST-ADOBO-CHK")` — two places), `$CHEDDAR`→`$FRIED_RICE`, `$TSHIRT_M`→`$HALO`, `$OAT`→`$GARLIC_RICE`, `$WHOLE`→`$PLAIN_RICE`, `$EXTRA_SHOT`→`$EXTRA_EGG`, `LATTE_B_QTY_MILLI`→`ADOBO_B_QTY_MILLI`, `LATTE_QTYS`→`ADOBO_QTYS`. Comment/echo copy updates:

- `# latte x2, oat milk + a repeated modifier (double shot = 'Extra shot' selected twice —` → `# adobo x2, garlic rice + a repeated modifier (double egg = 'Extra Egg' selected twice —`
- `fail "expected 3 modifier rows (oat + 2x extra shot), got $MODCOUNT"` → `fail "expected 3 modifier rows (garlic rice + 2x extra egg), got $MODCOUNT"`
- `echo "7. course 1: latte x2 + oat + double shot` → `echo "7. course 1: adobo x2 + garlic rice + double egg`
- `echo "9. course 2 added (cheddar, no modifiers required):` → `echo "9. course 2 added (garlic fried rice, no modifiers required):`
- `# Original latte qty on Tab B` → `# Original adobo qty on Tab B`
- Tab B line adds: adobo goes with `\"modifiers\":[\"$PLAIN_RICE\"]` (was `$WHOLE`); the other two lines are `$FRIED_RICE` then `$HALO`.
- `echo "12. Tab B: three lines (latte, cheddar, t-shirt), total=$TOTAL_B"` → `echo "12. Tab B: three lines (adobo, garlic fried rice, halo-halo), total=$TOTAL_B"`
- `fail "split latte qtys ($ADOBO_QTYS) do not sum back to 1.000"` → `"split adobo qtys …"`; `echo "14. split latte line qtys:` → `echo "14. split adobo line qtys:`
- `fail "receipt qty should be the allocated …"` / `echo "16. receipt renders the fractional qty:` — unchanged apart from the jq sku select.

Everything else (versions, split math, transfer, variance −700, close assertions) stays byte-identical.

- [ ] **Step 5: Verify against the running stack**

The stack still holds Task 9's all-three seed (retail-day didn't reseed). Copy the printed RST tokens from that seed run:

```bash
POS_DEVICE_TOKEN='<RST till 2>' POS_DEVICE_TOKEN_2='<RST till 1>' bash scripts/e2e-lunch-service.sh
```

Expected: all 23 steps pass, exit 0. Step 7's server-verified line total should print unit=22000 modsum=7000 line_total=58000.

- [ ] **Step 6: Commit**

```bash
git add scripts/e2e-lunch-service.sh
git commit -m "e2e: lunch service re-anchored on the Manila restaurant"
```

---

### Task 11: Rewrite `e2e-admin-day.sh` + full `make e2e`

**Files:**
- Modify: `scripts/e2e-admin-day.sh`

**Interfaces:**
- Consumes: location `GRC` (Task 6), `GRC / Till 1` token (Task 9's Makefile). All the script's absolute numbers (450/510/5510) survive: its Flat White has **no tax rate attached**, so totals are identical under VAT-inclusive pricing.

- [ ] **Step 1: Apply the edits**

- Header comment: `POS_DEVICE_TOKEN (Till 1 at Downtown — printed by …)` → `POS_DEVICE_TOKEN (GRC / Till 1 at the Manila Grocery — printed by …)`.
- `DEVICE=` guard message: `set POS_DEVICE_TOKEN to the Till 1 (Downtown) device token, …` → `set POS_DEVICE_TOKEN to the GRC / Till 1 (Manila Grocery) device token, …`.
- Location resolution:

```bash
# old
DOWNTOWN_ID=$(echo "$LOCATIONS" | jq -r '.data.items[] | select(.code=="DT") | .id')
[ -n "$DOWNTOWN_ID" ] && [ "$DOWNTOWN_ID" != "null" ] || fail "could not resolve Downtown (DT) location id"
echo "2. Downtown (DT) resolved: $DOWNTOWN_ID"
# new
GROCERY_ID=$(echo "$LOCATIONS" | jq -r '.data.items[] | select(.code=="GRC") | .id')
[ -n "$GROCERY_ID" ] && [ "$GROCERY_ID" != "null" ] || fail "could not resolve Manila Grocery (GRC) location id"
echo "2. Manila Grocery (GRC) resolved: $GROCERY_ID"
```

- Whole-file rename `$DOWNTOWN_ID` → `$GROCERY_ID` (Eve's role, Till 1 resolution, all three report URLs).
- The tax-mode comment above the variant creation becomes:

```bash
# The Manila Grocery is prices_include_tax=true (VAT lives inside the shelf price —
# docs/02-data-model.md). This untracked service item deliberately carries no tax rate
# at all (tax_rate_id omitted, which CreateVariantRequest allows), so every total below
# is the same round number in either tax mode and the modifier/reporting math stays
# exact. The 10% rate created above is still exercised end-to-end (created, listed,
# available for a real variant).
```

- Eve texts: `cashier @ DT` → `cashier @ GRC` (echo 10 and 11, and the fail message).
- Summary block: `printf "%-22s %s\n" "Location" "Downtown (DT)"` → `"Manila Grocery (GRC)"`; `"Staff hired" "Eve — cashier @ DT"` → `"Eve — cashier @ GRC"`.
- Everything else — every literal 450/500/510/5000/5510, the activation-code flow, audit checks — unchanged.

- [ ] **Step 2: Verify standalone**

```bash
make seed        # grocery-only default — admin-day's own precondition
```

Copy the printed `GRC / Till 1` token:

```bash
POS_ADMIN_EMAIL=admin@pos.test POS_ADMIN_PASSWORD=admin-dev-password \
POS_DEVICE_TOKEN='<GRC till 1 token>' POS_E2E_PIN=9876 bash scripts/e2e-admin-day.sh
```

Expected: all 34 steps pass, exit 0.

- [ ] **Step 3: Run the whole `make e2e`**

Run: `make e2e`
Expected: seed (all three catalogs) → retail-day green → lunch-service green → reseed (grocery) → admin-day green, exit 0.

- [ ] **Step 4: Commit**

```bash
git add scripts/e2e-admin-day.sh
git commit -m "e2e: admin day re-anchored on the Manila grocery"
```

---

### Task 12: Full suites + docs

**Files:**
- Modify: `docs/06-roadmap.md` (append a record after the activation-code entry)
- Modify: `CLAUDE.md` (Running-it section + Status section)

- [ ] **Step 1: Run everything**

```bash
make test        # backend + web + back-office suites, in containers
make e2e         # if not just run in Task 11
```

Expected: all green. Note the new backend test count (was 476; the register 112 and back-office 133 counts should be unchanged).

- [ ] **Step 2: Record in docs/06-roadmap.md**

Append a section following the file's existing entry style (read the tail of the file first and match it), covering: Downtown/London replaced by env-selected Manila catalogs (`POS_SEED_CATALOGS`, default `grocery`; `grocery`/`restaurant`/`cafe`, one location each — GRC/RST/CAF, Asia/Manila, VAT-inclusive, 12% VAT + exempt fresh produce); data as committed JSON under `backend/database/seeders/data/` (grocery sourced from Open Food Facts PH — real brands and EAN-13s; restaurant/cafe researched menus), guarded by `tests/Unit/SeedDataTest.php`; currency now `PHP`; e2e scripts re-anchored (retail-day and admin-day → GRC, lunch-service → RST) with `make e2e` seeding all three catalogs; the new suite counts.

- [ ] **Step 3: Update CLAUDE.md**

- "Running it" section, after the `make seed` line's table or the seed bullet: note `POS_SEED_CATALOGS=grocery,restaurant,cafe make seed` seeds all three Manila catalogs (default: grocery only).
- Status section: append a short paragraph in the established voice, e.g.:

> **Manila catalog seeders complete** — the Downtown/London demo seed is gone. `POS_SEED_CATALOGS` (default `grocery`) picks which Manila catalogs to seed — grocery (200 real PH items, Open Food Facts barcodes), restaurant (30 dishes, rice/size/spice/add-on modifiers), cafe (20 drinks & pastries) — each with its own location (GRC/RST/CAF, Asia/Manila, VAT-inclusive, `POS_CURRENCY=PHP`). Data is committed JSON under `backend/database/seeders/data/`, pinned by `tests/Unit/SeedDataTest.php`. e2e re-anchored: retail-day + admin-day at GRC, lunch-service at RST; `make e2e` seeds all three. Suites: <actual counts>.

- Update the stale seed-shape references that now lie: the M2 line "Seed with `php artisan migrate:fresh --seed`; it prints development PINs" stays true; nothing else in Status names Downtown/London seed specifics except the activation-code paragraph's "Seeder unchanged (still prints device tokens…)" — leave it, still true.

- [ ] **Step 4: Commit**

```bash
git add docs/06-roadmap.md CLAUDE.md
git commit -m "Docs: record the Manila catalog seeders"
```

- [ ] **Step 5: Final verification sweep**

```bash
git log --oneline main..HEAD    # story reads as the task sequence above
make test && make e2e           # one last full green run
```
