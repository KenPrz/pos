<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Rbac\Roles;
use App\Models\Discount;
use App\Models\Order;
use App\Models\ProductVariant;

/**
 * Task 9: `discounts.requires_supervisor` is enforced per-discount, not per-endpoint.
 * flag true keeps the old supervisor gate; flag false lets any staffer who can add a
 * line (order.line.add) apply it. RemoveDiscount is untouched — always supervisor-gated
 * (verified separately by grep, not by a test here).
 */
beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);

    // No tax_rate_id -> tax_rate_micros 0, so the numbers below are exact cents with no
    // rounding to reason about.
    $this->variant = ProductVariant::factory()->create(['price_cents' => 1000, 'track_inventory' => false]);
    $this->order = t9AddLine($this, $this->variant->id, '2');   // subtotal/total 2000c
});

// Pest file-scoped functions collide across test files if two share a name — namespaced
// per task to keep this file free to move or be copied.
function t9AddLine(object $t, string $variantId, string $qty): Order
{
    return app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $t->order->id, variantId: $variantId, qty: $qty,
        expectedVersion: Order::findOrFail($t->order->id)->version, actorId: $t->cashier->id, registerId: $t->register->id,
    ))->order;
}

function t9DiscountUrl(object $t): string
{
    return "/api/v1/orders/{$t->order->id}/discounts";
}

it('lets a cashier apply a discount marked cashier-safe', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => 'Loyalty 10%', 'requires_supervisor' => false]);
    $body = ['discount_id' => $discount->id, 'order_line_id' => null, 'reason' => 'Loyalty program'];

    $this->postJson(t9DiscountUrl($this), $body,
        staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $this->order->version])
        ->assertOk()
        ->assertJsonPath('data.order.discount_cents', 200)
        ->assertJsonPath('data.order.total_cents', 1800);
});

it('blocks a cashier from a supervisor-only discount with a clean 403', function (): void {
    $discount = Discount::factory()->percent(100_000)->create(['name' => 'Manager comp', 'requires_supervisor' => true]);
    $body = ['discount_id' => $discount->id, 'order_line_id' => null, 'reason' => 'Manager comp'];

    $this->postJson(t9DiscountUrl($this), $body,
        staffHeaders($this->register, $this->cashier) + ['If-Match' => (string) $this->order->version])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'discount_needs_supervisor');

    $this->assertDatabaseMissing('order_discounts', ['discount_id' => $discount->id]);
});

it('lets a supervisor apply either kind', function (): void {
    $cashierSafe = Discount::factory()->percent(100_000)->create(['name' => 'Loyalty 10%', 'requires_supervisor' => false]);
    $supervisorOnly = Discount::factory()->fixed(300)->create(['name' => 'Manager comp', 'requires_supervisor' => true]);

    $this->postJson(t9DiscountUrl($this), ['discount_id' => $cashierSafe->id, 'order_line_id' => null, 'reason' => 'Loyalty program'],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $this->order->version])
        ->assertOk()
        ->assertJsonPath('data.order.discount_cents', 200);

    $order = Order::findOrFail($this->order->id);

    $this->postJson(t9DiscountUrl($this), ['discount_id' => $supervisorOnly->id, 'order_line_id' => null, 'reason' => 'Manager comp'],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => (string) $order->version])
        ->assertOk()
        ->assertJsonPath('data.order.discount_cents', 500);   // 200 (10% of 2000) + 300 fixed
});
