<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->device = ['Authorization' => 'Bearer '.$this->register->createToken("device:{$this->register->id}", ['device'])->plainTextToken];
});

it('returns one denormalized payload with location-resolved prices', function (): void {
    // Pinned explicitly rather than relying on phpunit.xml's POS_CURRENCY=USD: that
    // `<env>` is soft and loses to a real environment variable (e.g. compose.dev.yml's
    // POS_CURRENCY=PHP under `make test-backend`) by design — see the Makefile's own
    // comment on why `-e` overrides beat phpunit.xml. Pinning config() here proves the
    // response mirrors config deterministically, regardless of which env it runs under.
    config(['pos.currency' => 'USD']);

    $variant = ProductVariant::factory()->create(['price_cents' => 1999]);
    DB::table('variant_location_prices')->insert([
        'variant_id' => $variant->id, 'location_id' => $this->location->id, 'price_cents' => 2499,
    ]);

    $response = $this->getJson('/api/v1/catalog', $this->device)->assertOk()->json('data');

    expect($response)->toHaveKeys(['categories', 'products', 'variants', 'modifier_groups', 'modifiers', 'tax_rates', 'currency']);

    $wire = collect($response['variants'])->firstWhere('id', $variant->id);
    expect($wire['price_cents'])->toBe(2499);   // resolved server-side; the register never resolves prices

    // The register renders whatever ISO-4217 code the server is configured with — it
    // never hardcodes a currency.
    expect($response['currency'])->toBe('USD');
});

it('hides inactive and soft-deleted variants', function (): void {
    $dead = ProductVariant::factory()->create(['is_active' => false]);
    $gone = ProductVariant::factory()->create();
    $gone->delete();

    $ids = collect($this->getJson('/api/v1/catalog', $this->device)->json('data.variants'))->pluck('id');

    expect($ids)->not->toContain($dead->id)->not->toContain($gone->id);
});

it('requires a device token', function (): void {
    $this->getJson('/api/v1/catalog')->assertStatus(401);
});
