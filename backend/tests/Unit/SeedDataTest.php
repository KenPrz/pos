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
