<?php

// backend/tests/Feature/Orders/TransferOrderTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->target = registerAt($this->location);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id, 'table_ref' => 'T7']);
    $this->targetShift = Shift::factory()->create(['register_id' => $this->target->id, 'opened_by' => $this->supervisor->id]);
});

it('moves the tab to the target drawer, keeping opened_by as history', function (): void {
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $this->target->id],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '0'])
        ->assertOk()
        ->assertJsonPath('data.order.register_id', $this->target->id)
        ->assertJsonPath('data.order.version', 1);

    $order = Order::findOrFail($this->order->id);
    expect($order->shift_id)->toBe($this->targetShift->id)
        ->and($order->opened_by)->toBe($this->cashier->id);
    $this->assertDatabaseHas('audit_log', ['action' => 'order.transfer', 'entity_id' => $this->order->id]);
});

it('is supervisor-tier', function (): void {
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $this->target->id],
        staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'])
        ->assertStatus(403);
});

it('refuses a target with no open shift', function (): void {
    $this->targetShift->forceFill(['closed_at' => now(), 'counted_cash_cents' => 0])->save();
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $this->target->id],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '0'])
        ->assertStatus(422)->assertJsonPath('error.code', 'transfer_target_no_shift');
});

it('refuses transferring to the shift the order is already on', function (): void {
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $this->register->id],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '0'])
        ->assertStatus(422)->assertJsonPath('error.code', 'transfer_same_shift');
});

it('404s a target register at another location', function (): void {
    $elsewhere = registerAt(provisionedLocation());
    Shift::factory()->create(['register_id' => $elsewhere->id]);
    $this->postJson("/api/v1/orders/{$this->order->id}/transfer", ['register_id' => $elsewhere->id],
        staffHeaders($this->register, $this->supervisor) + ['If-Match' => '0'])
        ->assertNotFound();
});
