<?php

// backend/tests/Feature/Admin/CatalogCrudTest.php
declare(strict_types=1);

use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

it('creates, lists, and archives a product end to end', function (): void {
    // kind CHECK is (goods, service) — see 2026_07_16_000400_create_catalog_tables.php.
    $create = $this->postJson('/api/v1/admin/products', ['name' => 'Flat White', 'kind' => 'goods'], $this->headers)
        ->assertCreated();
    $id = $create->json('data.product.id');

    $this->getJson('/api/v1/admin/products', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.items.0.name', 'Flat White');

    $this->patchJson("/api/v1/admin/products/{$id}", ['is_active' => false], $this->headers)->assertOk();
    expect(Product::findOrFail($id)->is_active)->toBeFalse();

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.product.update', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.product.create', 'entity_id' => $id]);
});

it('archived variants leave the register catalog but stay resolvable', function (): void {
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 500]);
    $this->patchJson("/api/v1/admin/variants/{$variant->id}", ['is_active' => false], $this->headers)->assertOk();

    // register catalog no longer carries it (GetCatalog filters is_active)
    $location = provisionedLocation();
    $register = registerAt($location);
    $token = $register->createToken('device')->plainTextToken;
    $catalog = $this->getJson('/api/v1/catalog', ['Authorization' => "Bearer {$token}"])->json('data');
    expect(collect($catalog['variants'])->pluck('id'))->not->toContain($variant->id);
    // but the row still exists for receipts/refunds
    expect(ProductVariant::withoutGlobalScopes()->find($variant->id))->not->toBeNull();
});

it('enforces sku uniqueness except against itself', function (): void {
    $a = ProductVariant::factory()->untracked()->create(['sku' => 'SKU-1']);
    $b = ProductVariant::factory()->untracked()->create(['sku' => 'SKU-2']);
    $this->patchJson("/api/v1/admin/variants/{$b->id}", ['sku' => 'SKU-1'], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
    $this->patchJson("/api/v1/admin/variants/{$a->id}", ['sku' => 'SKU-1'], $this->headers)->assertOk();
});

it('refuses to change product_id on a variant', function (): void {
    $variant = ProductVariant::factory()->untracked()->create();
    $other = Product::factory()->create();
    $this->patchJson("/api/v1/admin/variants/{$variant->id}", ['product_id' => $other->id], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('audits a variant reprice with the old and new price', function (): void {
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 500]);
    $this->patchJson("/api/v1/admin/variants/{$variant->id}", ['price_cents' => 700], $this->headers)->assertOk();

    $row = DB::table('audit_log')->where('action', 'admin.variant.update')->where('entity_id', $variant->id)->first();
    $payload = json_decode($row->payload, true);
    // jsonb reorders keys on round-trip — content-identical, never byte-identical.
    expect($payload['price_cents'])->toEqual(['from' => 500, 'to' => 700]);
});

it('refuses DELETE verbs everywhere — archive is the only removal', function (): void {
    $variant = ProductVariant::factory()->untracked()->create();
    $this->deleteJson("/api/v1/admin/variants/{$variant->id}", [], $this->headers)->assertStatus(405);
});

it('rejects a category as its own parent', function (): void {
    $cat = $this->postJson('/api/v1/admin/categories', ['name' => 'Drinks'], $this->headers)->json('data.category.id');
    $this->patchJson("/api/v1/admin/categories/{$cat}", ['parent_id' => $cat], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('keeps tax rates in micros and refuses out-of-range', function (): void {
    $this->postJson('/api/v1/admin/tax-rates', ['name' => 'VAT', 'rate_micros' => 1_000_001], $this->headers)
        ->assertStatus(400);
    $this->postJson('/api/v1/admin/tax-rates', ['name' => 'VAT', 'rate_micros' => 200_000], $this->headers)
        ->assertCreated()->assertJsonPath('data.tax_rate.rate_micros', 200000);
});

it('exposes a product\'s attached modifier groups as ordered ids on list and on attach', function (): void {
    $product = Product::factory()->create();
    $g1 = ModifierGroup::factory()->create(['name' => 'G1']);
    $g2 = ModifierGroup::factory()->create(['name' => 'G2']);

    // Attach in [g2, g1] order — the PUT response reflects it immediately (it eager
    // loads the pivot itself), and so should a completely fresh GET /products list,
    // which is what ProductEditor actually seeds its checkboxes from.
    $this->putJson("/api/v1/admin/products/{$product->id}/modifier-groups", ['group_ids' => [$g2->id, $g1->id]], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.product.modifier_group_ids', [$g2->id, $g1->id]);

    $list = $this->getJson('/api/v1/admin/products', $this->headers)->assertOk();
    $row = collect($list->json('data.items'))->firstWhere('id', $product->id);
    expect($row['modifier_group_ids'])->toBe([$g2->id, $g1->id]);
});

it('a non-admin token gets 403 on every admin catalog route', function (): void {
    $staff = User::factory()->create(['email' => 's@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];
    $this->getJson('/api/v1/admin/products', $headers)->assertStatus(403);
    $this->getJson('/api/v1/admin/categories', $headers)->assertStatus(403);
    $this->getJson('/api/v1/admin/tax-rates', $headers)->assertStatus(403);
    $this->getJson('/api/v1/admin/variants', $headers)->assertStatus(403);
});
