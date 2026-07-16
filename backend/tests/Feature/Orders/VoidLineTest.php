<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\VoidLine;
use App\Actions\Orders\VoidLineInput;
use App\Domain\Money\Quantity;
use App\Domain\Rbac\Roles;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\LineAlreadyVoided;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $this->variant = ProductVariant::factory()->create(['price_cents' => 1000, 'track_inventory' => true]);
    DB::transaction(fn () => app(StockLedger::class)->receive($this->variant->id, $this->location->id, Quantity::fromString('10')));

    $this->order = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, variantId: $this->variant->id, qty: '2',
        expectedVersion: 0, actorId: $this->cashier->id, registerId: $this->register->id,
    ))->order;
    $this->line = $this->order->lines->first();
});

it('voids the line, restocks, zeroes the totals, bumps the version', function (): void {
    $order = app(VoidLine::class)->execute(new VoidLineInput(
        orderId: $this->order->id, lineId: $this->line->id, registerId: $this->register->id,
        reason: 'Customer changed mind', expectedVersion: $this->order->version, actorId: $this->supervisor->id,
    ));

    expect($order->total_cents)->toBe(0)
        ->and($order->lines->first()->voided_at)->not->toBeNull()
        ->and($order->version)->toBe($this->order->version + 1);
    expect(DB::table('stock_levels')->where('variant_id', $this->variant->id)->value('qty'))->toBe('10.000');
    $this->assertDatabaseHas('stock_movements', ['reason' => 'refund', 'ref_type' => 'order_line', 'ref_id' => $this->line->id]);
    $this->assertDatabaseHas('audit_log', ['action' => 'order.line.void', 'entity_id' => $this->line->id]);
});

it('refuses a double void', function (): void {
    $input = new VoidLineInput($this->order->id, $this->line->id, $this->register->id, 'x', $this->order->version, $this->supervisor->id);
    $order = app(VoidLine::class)->execute($input);

    expect(fn () => app(VoidLine::class)->execute(new VoidLineInput(
        $this->order->id, $this->line->id, $this->register->id, 'x', $order->version, $this->supervisor->id,
    )))->toThrow(LineAlreadyVoided::class);
});

it('is denied to a cashier and allowed to a supervisor over HTTP', function (): void {
    $url = "/api/v1/orders/{$this->order->id}/lines/{$this->line->id}";
    $body = ['reason' => 'mis-scan'];

    $this->deleteJson($url, $body, staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertStatus(403);
    $this->deleteJson($url, $body, staffHeaders($this->register, $this->supervisor) + ['If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('data.order.total_cents', 0);
});

it('requires a reason', function (): void {
    $this->deleteJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}", [],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '1'])
        ->assertStatus(400);
});
