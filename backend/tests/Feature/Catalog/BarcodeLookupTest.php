<?php

declare(strict_types=1);

use App\Models\ProductVariant;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->device = ['Authorization' => 'Bearer '.$this->register->createToken("device:{$this->register->id}", ['device'])->plainTextToken];
});

it('resolves a barcode to a variant with the location price', function (): void {
    $variant = ProductVariant::factory()->create(['barcode' => '012345678905', 'price_cents' => 1999]);

    $this->getJson('/api/v1/catalog/lookup?barcode=012345678905', $this->device)
        ->assertOk()
        ->assertJsonPath('data.variant.id', $variant->id)
        ->assertJsonPath('data.variant.price_cents', 1999);
});

it('404s an unknown barcode with the standard envelope', function (): void {
    $this->getJson('/api/v1/catalog/lookup?barcode=000000000000', $this->device)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});
