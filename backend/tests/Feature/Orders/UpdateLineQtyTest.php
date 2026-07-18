<?php

// backend/tests/Feature/Orders/UpdateLineQtyTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Money\Quantity;
use App\Domain\Pricing\OrderTotals;
use App\Domain\Rbac\Roles;
use App\Domain\Stock\StockLedger;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderLineModifier;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $this->variant = ProductVariant::factory()->create(['price_cents' => 300, 'tax_rate_id' => null, 'track_inventory' => true]);
    app(StockLedger::class)->receive($this->variant->id, $this->location->id, Quantity::fromString('10'), null, 'seed');

    $this->line = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, registerId: $this->register->id, variantId: $this->variant->id,
        qty: '2', expectedVersion: 0, actorId: $this->cashier->id,
    ));
});

// The brief's draft referenced an App\Models\StockLevel Eloquent model that doesn't
// exist in this codebase — every other feature test reads stock_levels via DB::table,
// so this does too (see e.g. tests/Feature/Orders/AddLineTest.php).
function stockQty(object $t): string
{
    return DB::table('stock_levels')->where('variant_id', $t->variant->id)->where('location_id', $t->location->id)->value('qty');
}

it('raises qty, decrementing further stock', function (): void {
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '5'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('data.line.qty', '5.000')
        ->assertJsonPath('data.order.total_cents', 1500);
    expect(stockQty($this))->toBe('5.000');   // 10 − 2 − 3 more
});

it('lowers qty, restocking the difference', function (): void {
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '0.500'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('data.order.total_cents', 150);
    expect(stockQty($this))->toBe('9.500');
});

it('refuses insufficient stock on a raise', function (): void {
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '99'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertStatus(409)->assertJsonPath('error.code', 'insufficient_stock');
});

it('needs a supervisor to shrink a fired line', function (): void {
    $this->line->forceFill(['prep_state' => 'in_progress'])->save();
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'];
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '1'], $headers)
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    // raising the same fired line is normal service (another round)
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '3'], $headers)
        ->assertOk();
    // and a supervisor may shrink it
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '1'],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '2'])
        ->assertOk();
});

it('refuses a voided line', function (): void {
    // Split from the stale-version case below: the voided-line 409 aborts the Postgres
    // transaction (see CLAUDE.md), so a second assert in the same test would run against
    // a broken connection and never really exercise.
    $this->line->forceFill(['voided_at' => now()])->save();
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '1'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        // docs/03-api.md puts line_already_voided in the 409 table (it shares the row
        // with order_version_conflict); the brief's own draft said 422, but that
        // contradicts both the docs and the existing LineAlreadyVoided::httpStatus().
        ->assertStatus(409)->assertJsonPath('error.code', 'line_already_voided');
});

it('refuses a stale If-Match version', function (): void {
    // The add in beforeEach bumped the order to version 1, so If-Match: 0 is stale — the
    // optimistic-lock check in OpenOrderLock rejects it before any qty change lands.
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '1'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'])
        ->assertStatus(409)->assertJsonPath('error.code', 'order_version_conflict');
});

it('rescales modifier money from frozen snapshots', function (): void {
    // order_line_modifiers.modifier_id is NOT NULL with an FK to modifiers (see the
    // migration) — the brief's own note says to fall back to a real Modifier row when
    // the FK refuses null; the assertion below is about the rescale, not the FK.
    OrderLineModifier::create([
        'order_line_id' => $this->line->id, 'modifier_id' => Modifier::factory()->create()->id,
        'name_snapshot' => 'Extra shot', 'price_delta_cents' => 75,
    ]);
    $this->line->forceFill(['modifiers_total_cents' => 150])->save();   // 75 × qty 2
    app(OrderTotals::class)->recalculate($this->order->refresh());

    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", ['qty' => '3'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertOk()
        // (300 + 75) × 3
        ->assertJsonPath('data.line.line_total_cents', 1125);
});
