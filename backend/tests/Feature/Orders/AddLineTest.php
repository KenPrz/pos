<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Money\Quantity;
use App\Domain\Rbac\Roles;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\InsufficientStock;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);

    $nyc = TaxRate::factory()->create(['rate_micros' => 88750]);
    $this->variant = ProductVariant::factory()->create([
        'sku' => 'TSHIRT-BLUE-L', 'price_cents' => 1999, 'tax_rate_id' => $nyc->id, 'track_inventory' => true,
    ]);
    DB::transaction(fn () => app(StockLedger::class)->receive($this->variant->id, $this->location->id, Quantity::fromString('10')));
});

// Pest file-scoped functions collide across test files if two files declare the same
// name — namespaced per task to keep this file free to move or be copied.
function m3AddLine(object $t, string $qty = '1', ?int $version = null): Order
{
    $line = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $t->order->id,
        registerId: $t->register->id,
        variantId: $t->variant->id,
        qty: $qty,
        expectedVersion: $version ?? Order::findOrFail($t->order->id)->version,
        actorId: $t->cashier->id,
    ));

    return $line->order;
}

it('snapshots the line, decrements stock, recomputes totals, bumps version — atomically', function (): void {
    $order = m3AddLine($this, '2');

    $line = $order->lines->first();
    expect($line->name_snapshot)->not->toBe('')
        ->and($line->sku_snapshot)->toBe('TSHIRT-BLUE-L')
        ->and($line->unit_price_cents)->toBe(1999)
        ->and($line->tax_rate_micros)->toBe(88750)
        ->and($line->qty)->toBe('2.000')
        ->and($order->subtotal_cents)->toBe(3998)
        ->and($order->tax_cents)->toBe(355)
        ->and($order->total_cents)->toBe(4353)
        ->and($order->version)->toBe(1);

    expect(DB::table('stock_levels')->where('variant_id', $this->variant->id)->value('qty'))->toBe('8.000');
    $this->assertDatabaseHas('stock_movements', ['ref_type' => 'order_line', 'ref_id' => $line->id, 'reason' => 'sale']);
    $this->assertDatabaseHas('audit_log', ['action' => 'order.line.add', 'entity_id' => $line->id]);
});

it('uses the location override price, not the base', function (): void {
    DB::table('variant_location_prices')->insert([
        'variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'price_cents' => 2499,
    ]);

    expect(m3AddLine($this)->lines->first()->unit_price_cents)->toBe(2499);
});

it('a receipt-bound snapshot survives a later catalog reprice', function (): void {
    $order = m3AddLine($this);
    $this->variant->update(['price_cents' => 9999]);

    expect($order->lines()->first()->unit_price_cents)->toBe(1999);
});

it('rejects a stale version with the current one in details', function (): void {
    m3AddLine($this);   // version now 1

    expect(fn () => m3AddLine($this, '1', version: 0))->toThrow(OrderVersionConflict::class);
});

it('rejects lines on a closed order', function (): void {
    $this->order->forceFill(['status' => 'closed'])->save();

    expect(fn () => m3AddLine($this))->toThrow(OrderClosed::class);
});

it('refuses to oversell and leaves NO orphan line behind', function (): void {
    expect(fn () => m3AddLine($this, '11'))->toThrow(InsufficientStock::class);

    expect($this->order->lines()->count())->toBe(0)
        ->and(DB::table('stock_levels')->where('variant_id', $this->variant->id)->value('qty'))->toBe('10.000');
});

it('skips the stock lock for untracked variants', function (): void {
    $latte = ProductVariant::factory()->untracked()->create(['price_cents' => 350]);

    $line = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, registerId: $this->register->id, variantId: $latte->id, qty: '1',
        expectedVersion: 0, actorId: $this->cashier->id,
    ));

    expect($line)->toBeInstanceOf(OrderLine::class)
        ->and($line->order->lines->count())->toBe(1);
    $this->assertDatabaseMissing('stock_movements', ['variant_id' => $latte->id]);
});

it('adds a line over HTTP with If-Match and returns { order, line }', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/lines", ['variant_id' => $this->variant->id, 'qty' => '1'], $headers)
        ->assertCreated()
        ->assertJsonPath('data.order.version', 1)
        ->assertJsonPath('data.order.total_cents', 2176)   // 1999 + round(1999×0.08875)=177
        ->assertJsonPath('data.order.lines.0.sku', 'TSHIRT-BLUE-L')
        ->assertJsonPath('data.line.sku', 'TSHIRT-BLUE-L');
});

it('rejects modifiers in M3', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/lines",
        ['variant_id' => $this->variant->id, 'qty' => '1', 'modifiers' => ['x']], $headers)
        ->assertStatus(400);
});

it("404s a register at a different location adding a line to another location's order", function (): void {
    $otherLocation = provisionedLocation();
    $otherRegister = registerAt($otherLocation);
    $otherCashier = staffWithRole($otherLocation, Roles::CASHIER);
    $headers = staffHeaders($otherRegister, $otherCashier) + ['If-Match' => '0'];

    // The order belongs to $this->location; a register at $otherLocation must not reach it.
    $this->postJson("/api/v1/orders/{$this->order->id}/lines", ['variant_id' => $this->variant->id, 'qty' => '1'], $headers)
        ->assertStatus(404);
});

it("404s a register at a different location fetching another location's receipt", function (): void {
    $otherLocation = provisionedLocation();
    $otherRegister = registerAt($otherLocation);
    $otherCashier = staffWithRole($otherLocation, Roles::CASHIER);

    $this->getJson("/api/v1/orders/{$this->order->id}/receipt", staffHeaders($otherRegister, $otherCashier))
        ->assertStatus(404);
});
