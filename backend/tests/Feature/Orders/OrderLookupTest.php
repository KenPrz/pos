<?php

// tests/Feature/Orders/OrderLookupTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\ProductVariant;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create([
        'number' => 'DT-20260716-0042',
        'opened_by' => $this->cashier->id,
    ]);
});

it('fetches an order by id with its lines', function (): void {
    $this->getJson("/api/v1/orders/{$this->order->id}", staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.order.number', 'DT-20260716-0042')
        ->assertJsonPath('data.order.lines', []);
});

it('finds an order by its receipt number', function (): void {
    $this->getJson('/api/v1/orders?number=DT-20260716-0042', staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.orders.0.id', $this->order->id);
});

it('lists open orders at the register location', function (): void {
    $this->getJson('/api/v1/orders?status=open', staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonCount(1, 'data.orders');
});

it('requires at least one filter on the list', function (): void {
    $this->getJson('/api/v1/orders', staffHeaders($this->register, $this->cashier))
        ->assertStatus(400);
});

it('does not leak another location order', function (): void {
    $elsewhere = provisionedLocation();
    $other = Order::factory()->forRegister(registerAt($elsewhere))->create(['number' => 'X-1']);

    $this->getJson("/api/v1/orders/{$other->id}", staffHeaders($this->register, $this->cashier))
        ->assertStatus(404);
    $this->getJson('/api/v1/orders?number=X-1', staffHeaders($this->register, $this->cashier))
        ->assertOk()->assertJsonCount(0, 'data.orders');
});

it('rejects an absurd qty before Postgres has to', function (): void {
    $variant = ProductVariant::factory()->untracked()->create();

    $this->postJson("/api/v1/orders/{$this->order->id}/lines",
        ['variant_id' => $variant->id, 'qty' => '1000000000'],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'])
        ->assertStatus(400);
});
