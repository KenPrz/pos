<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Actions\Refunds\RefundLineInput;
use App\Actions\Refunds\RefundOrder;
use App\Actions\Refunds\RefundOrderInput;
use App\Actions\Orders\SplitOrder;
use App\Actions\Orders\SplitOrderInput;
use App\Actions\Orders\VoidOrder;
use App\Actions\Orders\VoidOrderInput;
use App\Actions\Reports\GetZReport;
use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Actions\Shifts\RecordCashMovement;
use App\Actions\Shifts\RecordCashMovementInput;
use App\Domain\Rbac\Roles;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);

    $this->shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $this->register->id, openingFloatCents: 20000, actorId: $this->cashier->id,
    ));
});

// Pest file-scoped functions collide across test files if two share a name — namespaced
// per task to keep this file free to move or be copied.
function t12AddLine(object $t, string $orderId, string $variantId, string $qty): Order
{
    return app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $orderId, registerId: $t->register->id, variantId: $variantId, qty: $qty,
        expectedVersion: Order::findOrFail($orderId)->version, actorId: $t->cashier->id,
    ))->order;
}

function t12Pay(object $t, string $orderId, string $driver, int $amountCents, ?string $reference = null): Order
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $orderId, registerId: $t->register->id, driver: $driver,
        amountCents: $amountCents, tenderedCents: $driver === 'cash' ? $amountCents : null,
        reference: $reference, expectedVersion: Order::findOrFail($orderId)->version, actorId: $t->cashier->id,
    ))->order;
}

it('reads the drawer\'s whole day off the ledgers — open (the running X) and then closed', function (): void {
    // Cash sale: two 0%-tax lines (1000 + 1176 = 2176), so the later refund of exactly
    // 1000 is a clean, whole-line refund — nothing to derive, nothing to round.
    $variantA = ProductVariant::factory()->untracked()->create(['price_cents' => 1000]);
    $variantB = ProductVariant::factory()->untracked()->create(['price_cents' => 1176]);

    $cashOrder = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $cashOrder = t12AddLine($this, $cashOrder->id, $variantA->id, '1');
    $lineA = $cashOrder->lines->first();
    $cashOrder = t12AddLine($this, $cashOrder->id, $variantB->id, '1');

    // Hand-check before trusting the refund derives 1000 from it.
    expect($lineA->line_total_cents)->toBe(1000)
        ->and($lineA->tax_cents)->toBe(0)
        ->and($cashOrder->total_cents)->toBe(2176);

    $cashOrder = t12Pay($this, $cashOrder->id, 'cash', 2176);
    expect($cashOrder->status)->toBe(OrderStatus::Closed);

    // Card sale: one untracked-variant line, paid in full by external_card.
    $cardVariant = ProductVariant::factory()->untracked()->create(['price_cents' => 500]);
    $cardOrder = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $cardOrder = t12AddLine($this, $cardOrder->id, $cardVariant->id, '1');
    expect($cardOrder->total_cents)->toBe(500);
    $cardOrder = t12Pay($this, $cardOrder->id, 'external_card', 500, 'auth 001122');
    expect($cardOrder->status)->toBe(OrderStatus::Closed);

    // Refund the whole 1000-cent line, cash — derived, not client-supplied.
    $refund = app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $cashOrder->id, registerId: $this->register->id, driver: 'cash',
        reason: 'Customer return', lines: [new RefundLineInput($lineA->id, '1', restock: false)],
        actorId: $this->supervisor->id,
    ));
    expect($refund->amount_cents)->toBe(1000);

    // A payout — the fraud surface, supervisor only.
    app(RecordCashMovement::class)->execute(new RecordCashMovementInput(
        shiftId: $this->shift->id, registerId: $this->register->id, kind: 'payout',
        amountCents: 300, reason: 'Window cleaner', actorId: $this->supervisor->id,
    ));

    // --- The shift is still open: its Z is the running X-report. ---
    $report = app(GetZReport::class)->execute($this->shift->id, $this->register->id);

    expect($report->shift->id)->toBe($this->shift->id)
        ->and($report->shift->closed_at)->toBeNull()
        ->and($report->salesByDriver)->toBe(['cash' => 2176, 'external_card' => 500])
        ->and($report->refundsByDriver)->toBe(['cash' => 1000])
        ->and($report->movements)->toBe(['paid_in' => 0, 'payout' => 300, 'drop' => 0])
        ->and($report->ordersClosed)->toBe(2)
        ->and($report->ordersVoided)->toBe(0)
        ->and($report->expectedCashCents)->toBe(20000 + 2176 - 1000 - 300)
        ->and($report->expectedCashCents)->toBe(20876);

    // Same, over HTTP, as the cashier — it's their own drawer, and REPORT_Z_VIEW is
    // location-scoped rather than ownership-scoped, so this is not a special case.
    $this->getJson('/api/v1/reports/z?shift_id='.$this->shift->id, staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.shift.id', $this->shift->id)
        ->assertJsonPath('data.shift.variance_cents', null)
        ->assertJsonPath('data.sales_by_driver.cash', 2176)
        ->assertJsonPath('data.sales_by_driver.external_card', 500)
        ->assertJsonPath('data.refunds_by_driver.cash', 1000)
        ->assertJsonPath('data.movements.paid_in', 0)
        ->assertJsonPath('data.movements.payout', 300)
        ->assertJsonPath('data.movements.drop', 0)
        ->assertJsonPath('data.orders_closed', 2)
        ->assertJsonPath('data.orders_voided', 0)
        ->assertJsonPath('data.expected_cash_cents', 20876);

    // --- Close the shift counted exact: variance is zero, and the Z now carries it. ---
    $closed = app(CloseShift::class)->execute(new CloseShiftInput(
        $this->shift->id, $this->register->id, 20876, null, $this->cashier->id,
    ));
    expect($closed->variance_cents)->toBe(0);

    $closedReport = app(GetZReport::class)->execute($this->shift->id, $this->register->id);
    expect($closedReport->shift->closed_at)->not->toBeNull()
        ->and($closedReport->shift->variance_cents)->toBe(0)
        ->and($closedReport->expectedCashCents)->toBe(20876)
        ->and($closedReport->salesByDriver)->toBe(['cash' => 2176, 'external_card' => 500]);

    $this->getJson('/api/v1/reports/z?shift_id='.$this->shift->id, staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.shift.variance_cents', 0)
        ->assertJsonPath('data.expected_cash_cents', 20876);
});

it("is scoped to the acting register's location, not to the shift's own register", function (): void {
    $otherLocation = provisionedLocation();
    $otherRegister = registerAt($otherLocation);
    $otherCashier = staffWithRole($otherLocation, Roles::CASHIER);
    $otherShift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $otherRegister->id, openingFloatCents: 5000, actorId: $otherCashier->id,
    ));

    // A different location's shift is a 404, not a 403 leak.
    $this->getJson('/api/v1/reports/z?shift_id='.$otherShift->id, staffHeaders($this->register, $this->cashier))
        ->assertStatus(404);

    // A different register at the SAME location can pull it — a supervisor checks any
    // drawer at their store, not just the one they're standing at.
    $secondRegister = registerAt($this->location);
    $this->getJson('/api/v1/reports/z?shift_id='.$this->shift->id, staffHeaders($secondRegister, $this->supervisor))
        ->assertOk()
        ->assertJsonPath('data.shift.id', $this->shift->id);
});

it('requires shift_id', function (): void {
    $this->getJson('/api/v1/reports/z', staffHeaders($this->register, $this->cashier))
        ->assertStatus(400);
});

it('separates a split original from a genuine void — orders_voided excludes splits, orders_split counts only them', function (): void {
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 1000]);

    // A split: the original is voided with void_reason "split into ..." and must NOT
    // count as orders_voided — it must count as orders_split instead.
    $splitOrder = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $splitOrder = t12AddLine($this, $splitOrder->id, $variant->id, '1');
    app(SplitOrder::class)->execute(new SplitOrderInput(
        orderId: $splitOrder->id, registerId: $this->register->id, ways: 2,
        expectedVersion: Order::findOrFail($splitOrder->id)->version, actorId: $this->cashier->id,
    ));

    // A genuine void — must count as orders_voided, and must NOT count as orders_split.
    $voidedOrder = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $voidedOrder = t12AddLine($this, $voidedOrder->id, $variant->id, '1');
    app(VoidOrder::class)->execute(new VoidOrderInput(
        orderId: $voidedOrder->id, registerId: $this->register->id, reason: 'walkout',
        expectedVersion: $voidedOrder->version, actorId: $this->supervisor->id,
    ));

    $report = app(GetZReport::class)->execute($this->shift->id, $this->register->id);
    expect($report->ordersVoided)->toBe(1)
        ->and($report->ordersSplit)->toBe(1);

    $this->getJson('/api/v1/reports/z?shift_id='.$this->shift->id, staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.orders_voided', 1)
        ->assertJsonPath('data.orders_split', 1);
});
