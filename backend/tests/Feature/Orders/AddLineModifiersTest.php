<?php

// backend/tests/Feature/Orders/AddLineModifiersTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);

    $this->latte = ProductVariant::factory()->untracked()->create(['price_cents' => 500, 'tax_rate_id' => null]);
    $product = Product::findOrFail($this->latte->product_id);

    $this->milk = ModifierGroup::create(['name' => 'Milk', 'min_select' => 1, 'max_select' => 1]);
    $this->oat = Modifier::create(['group_id' => $this->milk->id, 'name' => 'Oat', 'price_delta_cents' => 60, 'position' => 0, 'is_active' => true]);
    $this->whole = Modifier::create(['group_id' => $this->milk->id, 'name' => 'Whole', 'price_delta_cents' => 0, 'position' => 1, 'is_active' => true]);

    $this->extras = ModifierGroup::create(['name' => 'Extras', 'min_select' => 0, 'max_select' => 3]);
    $this->shot = Modifier::create(['group_id' => $this->extras->id, 'name' => 'Extra shot', 'price_delta_cents' => 75, 'position' => 0, 'is_active' => true]);

    $product->modifierGroups()->attach([$this->milk->id => ['position' => 0], $this->extras->id => ['position' => 1]]);

    $this->headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];
});

function addLine(object $t, array $body, ?array $headers = null): TestResponse
{
    return $t->postJson("/api/v1/orders/{$t->order->id}/lines",
        // PHP's array union `+` keeps the LEFT side on key collision — $body must come
        // first so a caller-supplied 'qty' actually overrides the '1' default.
        $body + ['variant_id' => $t->latte->id, 'qty' => '1'], $headers ?? $t->headers);
}

it('snapshots modifiers and prices per unit times qty', function (): void {
    // 2 lattes, oat (+60) and a double extra shot (+75 ×2, repeats legal): per unit +210.
    $response = addLine($this, ['qty' => '2', 'modifiers' => [$this->oat->id, $this->shot->id, $this->shot->id]]);
    $response->assertCreated()
        ->assertJsonPath('data.line.modifiers.0.name', 'Oat')
        // (500×2) + (210×2) = 1420
        ->assertJsonPath('data.line.line_total_cents', 1420)
        ->assertJsonPath('data.order.total_cents', 1420);
    expect($response->json('data.line.modifiers'))->toHaveCount(3);
});

it('rounds a fractional qty through the one primitive', function (): void {
    // 0.500 × (500 + 75) = 287.5 → 288 (half away from zero)
    addLine($this, ['qty' => '0.500', 'modifiers' => [$this->whole->id, $this->shot->id]])
        ->assertCreated()
        ->assertJsonPath('data.line.line_total_cents', 288);
});

it('refuses a missing required group', function (): void {
    addLine($this, ['modifiers' => []])
        ->assertStatus(422)->assertJsonPath('error.code', 'modifier_group_required');
});

it('refuses overshooting max_select', function (): void {
    addLine($this, ['modifiers' => [$this->oat->id, $this->whole->id]])
        ->assertStatus(422)->assertJsonPath('error.code', 'modifier_group_required');
});

it('refuses a modifier from a group not attached to the product', function (): void {
    $rogueGroup = ModifierGroup::create(['name' => 'Rogue', 'min_select' => 0, 'max_select' => null]);
    $rogue = Modifier::create(['group_id' => $rogueGroup->id, 'name' => 'Rogue mod', 'price_delta_cents' => 0, 'position' => 0, 'is_active' => true]);
    addLine($this, ['modifiers' => [$this->oat->id, $rogue->id]])
        ->assertStatus(422)->assertJsonPath('error.code', 'modifier_not_applicable');
});

it('refuses a line whose resolved total would go negative', function (): void {
    $discount = Modifier::create(['group_id' => $this->extras->id, 'name' => 'Comp', 'price_delta_cents' => -600, 'position' => 1, 'is_active' => true]);
    addLine($this, ['modifiers' => [$this->oat->id, $discount->id]])
        ->assertStatus(422)->assertJsonPath('error.code', 'line_total_negative');
});

it('renders modifiers on the receipt from snapshots', function (): void {
    $order = addLine($this, ['modifiers' => [$this->oat->id]])->json('data.order');
    // rename after the fact — receipt must not notice
    $this->oat->update(['name' => 'RENAMED']);
    $this->postJson("/api/v1/orders/{$order['id']}/payments",
        ['driver' => 'cash', 'amount_cents' => $order['total_cents'], 'tendered_cents' => $order['total_cents']],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $order['version'], 'Idempotency-Key' => (string) Str::uuid()],
    )->assertCreated();
    $this->getJson("/api/v1/orders/{$order['id']}/receipt", staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.lines.0.modifiers.0.name', 'Oat')
        ->assertJsonPath('data.lines.0.modifiers.0.price_delta_cents', 60);
});
