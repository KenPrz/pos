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
        orderId: $orderId, registerId: $t->register->id,
        paymentMethodCode: $driver === 'cash' ? 'CASH' : 'CARD',
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
        originalOrderId: $cashOrder->id, registerId: $this->register->id, paymentMethodCode: 'CASH',
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
        ->and($report->salesByMethod)->toEqual(['CASH' => 2176, 'CARD' => 500])
        ->and($report->refundsByMethod)->toEqual(['CASH' => 1000])
        ->and($report->refundsByGroup)->toEqual(['CASH' => 1000])
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
        ->assertJsonPath('data.sales_by_method.CASH', 2176)
        ->assertJsonPath('data.sales_by_method.CARD', 500)
        ->assertJsonPath('data.refunds_by_method.CASH', 1000)
        ->assertJsonPath('data.refunds_by_group.CASH', 1000)
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
        ->and($closedReport->salesByMethod)->toEqual(['CASH' => 2176, 'CARD' => 500]);

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

/** A closed order paid in full on one method, in the open shift. Own name — Pest's
 *  file-scoped functions collide across files, and this file already namespaces its own. */
function zmTenderedOrder(object $t, string $methodCode, int $cents): void
{
    // forRegister() wires location/register/shift into one chain and computes
    // business_date in the LOCATION's timezone, which ledger-basis reports depend on.
    $order = Order::factory()->forRegister($t->register)->create([
        'opened_by' => $t->cashier->id,
        'subtotal_cents' => $cents,
        'total_cents' => $cents,
    ]);
    // Eloquent create() never hydrates DB column defaults (version's included) —
    // refresh so expectedVersion below is the real row value, not null.
    $order->refresh();

    app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $order->id,
        registerId: $t->register->id,
        paymentMethodCode: $methodCode,
        amountCents: $cents,
        tenderedCents: $cents,
        reference: null,
        expectedVersion: $order->version,
        actorId: $t->cashier->id,
    ));
}

it('breaks sales down by method and rolls them up by group', function (): void {
    // Two groups sharing one driver — the whole reason the rollup is by GROUP and not by
    // driver: a supervisor counting a drawer needs GCash apart from Visa.
    $ewallet = \App\Models\PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card', 'sort_order' => 2,
    ]);
    \App\Models\PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $ewallet->id,
        'code' => 'GCASH', 'name' => 'GCash',
    ]);

    zmTenderedOrder($this, 'CASH', 1000);
    zmTenderedOrder($this, 'CARD', 2000);
    zmTenderedOrder($this, 'GCASH', 3000);

    $z = app(GetZReport::class)->execute($this->shift->id, $this->register->id);

    expect($z->salesByMethod)->toEqual(['CASH' => 1000, 'CARD' => 2000, 'GCASH' => 3000]);
    // GCash rolls into EWALLET, not into CARD, even though both drive external_card.
    expect($z->salesByGroup)->toEqual(['CASH' => 1000, 'CARD' => 2000, 'EWALLET' => 3000]);
});

it('omits methods with no activity', function (): void {
    zmTenderedOrder($this, 'CASH', 1000);

    // Same shape the driver maps had: only codes with money against them appear.
    $z = app(GetZReport::class)->execute($this->shift->id, $this->register->id);

    expect($z->salesByMethod)->not->toHaveKey('CARD');
    expect($z->salesByGroup)->not->toHaveKey('CARD');
});

it('rolls refunds up by group across two groups that share the cash driver', function (): void {
    // Refundability is a DRIVER capability — RefundOrder gates on
    // Capabilities::refundable, and only `cash`-driver methods qualify (external_card's
    // is false). So unlike the sales-side EWALLET/CARD case above, proving refund group
    // rollup needs two groups that BOTH drive `cash`: the location's own CASH group,
    // plus a second PETTY group added here.
    $petty = \App\Models\PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'PETTY', 'name' => 'Petty cash', 'driver' => 'cash', 'sort_order' => 3,
    ]);
    \App\Models\PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $petty->id,
        'code' => 'PETTYCASH', 'name' => 'Petty cash drawer',
    ]);

    $variantA = ProductVariant::factory()->untracked()->create(['price_cents' => 1000]);
    $variantB = ProductVariant::factory()->untracked()->create(['price_cents' => 700]);

    $orderA = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $orderA = t12AddLine($this, $orderA->id, $variantA->id, '1');
    $lineA = $orderA->lines->first();
    $orderA = t12Pay($this, $orderA->id, 'cash', $orderA->total_cents);

    $orderB = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $orderB = t12AddLine($this, $orderB->id, $variantB->id, '1');
    $lineB = $orderB->lines->first();
    $orderB = t12Pay($this, $orderB->id, 'cash', $orderB->total_cents);

    // Both sales were rung up on CASH; the refunds are issued through two different
    // cash-driver methods — CASH and PETTYCASH — so the group rollup must keep the two
    // buckets apart rather than collapsing them into one `cash` bucket.
    app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $orderA->id, registerId: $this->register->id, paymentMethodCode: 'CASH',
        reason: 'test return', lines: [new RefundLineInput($lineA->id, '1', restock: false)],
        actorId: $this->supervisor->id,
    ));
    app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $orderB->id, registerId: $this->register->id, paymentMethodCode: 'PETTYCASH',
        reason: 'test return', lines: [new RefundLineInput($lineB->id, '1', restock: false)],
        actorId: $this->supervisor->id,
    ));

    $z = app(GetZReport::class)->execute($this->shift->id, $this->register->id);

    expect($z->refundsByMethod)->toEqual(['CASH' => 1000, 'PETTYCASH' => 700]);
    expect($z->refundsByGroup)->toEqual(['CASH' => 1000, 'PETTY' => 700]);
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
