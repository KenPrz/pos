<?php

declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Order;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
});

it('sets and clears the table ref', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];
    $this->patchJson("/api/v1/orders/{$this->order->id}", ['table_ref' => 'T12'], $headers)
        ->assertOk()->assertJsonPath('data.order.table_ref', 'T12')->assertJsonPath('data.order.version', 1);

    $this->patchJson("/api/v1/orders/{$this->order->id}", ['table_ref' => null],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '1'])
        ->assertOk()->assertJsonPath('data.order.table_ref', null);

    $this->assertDatabaseHas('audit_log', ['action' => 'order.table_ref', 'entity_id' => $this->order->id]);
});

it('feeds the floor view: open orders carry opener name, opened_at and due_cents', function (): void {
    $this->order->forceFill(['table_ref' => 'T5', 'total_cents' => 900, 'paid_cents' => 400])->save();
    $this->getJson('/api/v1/orders?status=open', staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.orders.0.table_ref', 'T5')
        ->assertJsonPath('data.orders.0.opened_by_name', $this->cashier->name)
        ->assertJsonPath('data.orders.0.due_cents', 500);
});
