<?php

// backend/tests/Feature/Admin/ModifierDiscountCrudTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Money\DiscountKind;
use App\Domain\Rbac\Roles;
use App\Models\Discount;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderLineModifier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;

beforeEach(function (): void {
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

it('creates, lists, and updates a modifier group', function (): void {
    $create = $this->postJson('/api/v1/admin/modifier-groups', ['name' => 'Milk', 'min_select' => 0, 'max_select' => 1], $this->headers)
        ->assertCreated();
    $id = $create->json('data.modifier_group.id');

    $this->getJson('/api/v1/admin/modifier-groups', $this->headers)
        ->assertOk()->assertJsonPath('data.items.0.name', 'Milk');

    $this->patchJson("/api/v1/admin/modifier-groups/{$id}", ['min_select' => 1], $this->headers)->assertOk();
    expect(ModifierGroup::findOrFail($id)->min_select)->toBe(1);

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.modifier_group.create', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.modifier_group.update', 'entity_id' => $id]);
});

it('refuses an inverted select range on create', function (): void {
    $this->postJson('/api/v1/admin/modifier-groups', ['name' => 'Bad', 'min_select' => 2, 'max_select' => 1], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('accepts a numeric-string max_select on create without a strict_types TypeError', function (): void {
    // The `integer` validation rule accepts numeric strings; CreateModifierGroupInput's
    // max_select is a readonly ?int constructor param under strict_types, so an uncast
    // string here would 500 instead of validate.
    $this->postJson('/api/v1/admin/modifier-groups', ['name' => 'Milk', 'min_select' => '0', 'max_select' => '1'], $this->headers)
        ->assertCreated()
        ->assertJsonPath('data.modifier_group.max_select', 1);
});

it('refuses an inverted select range on patch, checked against the merged state', function (): void {
    $group = ModifierGroup::factory()->create(['min_select' => 0, 'max_select' => 2]);
    // min_select alone would be fine in isolation; against the existing max_select=2 it inverts.
    $this->patchJson("/api/v1/admin/modifier-groups/{$group->id}", ['min_select' => 3], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
    expect($group->refresh()->min_select)->toBe(0);
});

it('creates, lists, and updates a modifier, accepting a negative price delta', function (): void {
    $group = ModifierGroup::factory()->create();
    $create = $this->postJson('/api/v1/admin/modifiers', [
        'group_id' => $group->id, 'name' => 'Oat milk', 'price_delta_cents' => -50,
    ], $this->headers)->assertCreated();
    $id = $create->json('data.modifier.id');
    expect(Modifier::findOrFail($id)->price_delta_cents)->toBe(-50);

    $this->getJson('/api/v1/admin/modifiers', $this->headers)
        ->assertOk()->assertJsonPath('data.items.0.name', 'Oat milk');

    $this->patchJson("/api/v1/admin/modifiers/{$id}", ['price_delta_cents' => -75], $this->headers)->assertOk();
    expect(Modifier::findOrFail($id)->price_delta_cents)->toBe(-75);

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.modifier.create', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.modifier.update', 'entity_id' => $id]);
});

it('refuses changing a modifier group_id on patch', function (): void {
    $modifier = Modifier::factory()->create();
    $other = ModifierGroup::factory()->create();
    $this->patchJson("/api/v1/admin/modifiers/{$modifier->id}", ['group_id' => $other->id], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
    expect($modifier->refresh()->group_id)->not->toBe($other->id);
});

it('creates, lists, and updates a discount', function (): void {
    $create = $this->postJson('/api/v1/admin/discounts', [
        'name' => '10% off', 'kind' => 'percent', 'percent_micros' => 100_000, 'scope' => 'order',
    ], $this->headers)->assertCreated();
    $id = $create->json('data.discount.id');

    $this->getJson('/api/v1/admin/discounts', $this->headers)
        ->assertOk()->assertJsonPath('data.items.0.name', '10% off');

    $this->patchJson("/api/v1/admin/discounts/{$id}", ['percent_micros' => 150_000], $this->headers)->assertOk();
    expect(Discount::findOrFail($id)->percent_micros)->toBe(150_000);

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.discount.create', 'entity_id' => $id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.discount.update', 'entity_id' => $id]);
});

it('refuses a percent discount carrying amount_cents on create', function (): void {
    $this->postJson('/api/v1/admin/discounts', [
        'name' => 'bad', 'kind' => 'percent', 'percent_micros' => 100_000, 'amount_cents' => 500, 'scope' => 'order',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a fixed discount missing amount_cents on create', function (): void {
    $this->postJson('/api/v1/admin/discounts', [
        'name' => 'bad', 'kind' => 'fixed', 'scope' => 'order',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses a fixed discount carrying percent_micros on create', function (): void {
    $this->postJson('/api/v1/admin/discounts', [
        'name' => 'bad', 'kind' => 'fixed', 'amount_cents' => 500, 'percent_micros' => 1, 'scope' => 'order',
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('refuses flipping kind via patch unless the opposing value is dropped in the same request', function (): void {
    $discount = Discount::factory()->percent(100_000)->create();

    // amount_cents alone, against the existing percent_micros still on the row, inverts.
    $this->patchJson("/api/v1/admin/discounts/{$discount->id}", ['kind' => 'fixed', 'amount_cents' => 500], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    // dropping percent_micros explicitly in the same patch makes the merged state consistent.
    $this->patchJson("/api/v1/admin/discounts/{$discount->id}", [
        'kind' => 'fixed', 'amount_cents' => 500, 'percent_micros' => null,
    ], $this->headers)->assertOk();
    expect($discount->refresh())
        ->kind->toBe(DiscountKind::Fixed)
        ->amount_cents->toBe(500)
        ->percent_micros->toBeNull();
});

it('replaces a product\'s modifier groups in PUT order, position = array index', function (): void {
    $product = Product::factory()->create();
    $g1 = ModifierGroup::factory()->create(['name' => 'G1']);
    $g2 = ModifierGroup::factory()->create(['name' => 'G2']);

    $this->putJson("/api/v1/admin/products/{$product->id}/modifier-groups", ['group_ids' => [$g2->id, $g1->id]], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.product.modifier_groups.0.id', $g2->id)
        ->assertJsonPath('data.product.modifier_groups.0.position', 0)
        ->assertJsonPath('data.product.modifier_groups.1.id', $g1->id)
        ->assertJsonPath('data.product.modifier_groups.1.position', 1);

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.product.modifier_groups', 'entity_id' => $product->id]);
    $this->assertDatabaseCount('product_modifier_groups', 2);

    $this->putJson("/api/v1/admin/products/{$product->id}/modifier-groups", ['group_ids' => [$g1->id]], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.product.modifier_groups.0.id', $g1->id)
        ->assertJsonCount(1, 'data.product.modifier_groups');
    $this->assertDatabaseCount('product_modifier_groups', 1);
});

it('attaches modifier groups to a product with existing order lines without disturbing frozen snapshots', function (): void {
    $location = provisionedLocation();
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);
    $order = Order::factory()->forRegister($register)->create(['opened_by' => $cashier->id]);
    $variant = ProductVariant::factory()->create(['price_cents' => 300, 'tax_rate_id' => null, 'track_inventory' => false]);

    $line = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $order->id, registerId: $register->id, variantId: $variant->id,
        qty: '1', expectedVersion: 0, actorId: $cashier->id,
    ));

    $modifier = Modifier::factory()->create(['price_delta_cents' => 75]);
    OrderLineModifier::create([
        'order_line_id' => $line->id, 'modifier_id' => $modifier->id,
        'name_snapshot' => 'Extra shot', 'price_delta_cents' => 75,
    ]);
    $line->forceFill(['modifiers_total_cents' => 75])->save();

    $group = ModifierGroup::factory()->create();
    $this->putJson("/api/v1/admin/products/{$variant->product_id}/modifier-groups", ['group_ids' => [$group->id]], $this->headers)
        ->assertOk();

    // Tightening/editing the modifier after the fact affects only future add-lines —
    // this existing line's frozen snapshot must not move.
    $this->patchJson("/api/v1/admin/modifiers/{$modifier->id}", ['price_delta_cents' => 999], $this->headers)->assertOk();

    expect($line->refresh()->modifiers_total_cents)->toBe(75);
    expect(OrderLineModifier::first()->price_delta_cents)->toBe(75);
});
