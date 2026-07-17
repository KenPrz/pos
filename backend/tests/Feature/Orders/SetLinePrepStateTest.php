<?php
// backend/tests/Feature/Orders/SetLinePrepStateTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\ProductVariant;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 500, 'tax_rate_id' => null]);
    $this->line = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, registerId: $this->register->id, variantId: $variant->id,
        qty: '1', expectedVersion: 0, actorId: $this->cashier->id,
    ));
});

it('fires and readies a line without bumping the order version', function (): void {
    $headers = staffHeaders($this->register, $this->cashier);
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}/prep", ['state' => 'in_progress'], $headers)
        ->assertOk()
        ->assertJsonPath('data.line.prep_state', 'in_progress')
        ->assertJsonPath('data.order.version', 1);   // still 1 from the add — prep did not bump

    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}/prep", ['state' => 'ready'], $headers)
        ->assertOk()->assertJsonPath('data.line.prep_state', 'ready');

    $this->assertDatabaseHas('audit_log', ['action' => 'order.line.prep', 'entity_id' => $this->line->id]);
});

it('rejects an unknown state as validation, and a voided line as domain refusal', function (): void {
    $headers = staffHeaders($this->register, $this->cashier);
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}/prep", ['state' => 'burnt'], $headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');

    $this->line->forceFill(['voided_at' => now()])->save();
    $this->patchJson("/api/v1/orders/{$this->order->id}/lines/{$this->line->id}/prep", ['state' => 'ready'], $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'line_already_voided');
});
