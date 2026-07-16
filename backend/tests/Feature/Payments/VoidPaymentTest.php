<?php

declare(strict_types=1);

use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Actions\Payments\VoidPayment;
use App\Actions\Payments\VoidPaymentInput;
use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Domain\Rbac\Roles;
use App\Domain\Shifts\ShiftTotals;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->shift = Shift::factory()->create([
        'register_id' => $this->register->id,
        'opened_by' => $this->cashier->id,
        'opening_float_cents' => 20000,
    ]);
    $this->order = Order::factory()->forRegister($this->register)->create([
        'opened_by' => $this->cashier->id,
        'total_cents' => 5000,
        'subtotal_cents' => 5000,
    ]);
});

/** Captures a full cash tender, closing the order (paid in full). */
function t5TakeCash(object $t): Payment
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $t->order->id,
        registerId: $t->register->id,
        driver: 'cash',
        amountCents: 5000,
        tenderedCents: 5000,
        reference: null,
        expectedVersion: Order::findOrFail($t->order->id)->version,
        actorId: $t->cashier->id,
    ));
}

function t5VoidPayment(object $t, string $paymentId, string $reason = 'Refund', ?string $actorId = null): Payment
{
    return app(VoidPayment::class)->execute(new VoidPaymentInput(
        paymentId: $paymentId,
        registerId: $t->register->id,
        reason: $reason,
        actorId: $actorId ?? $t->supervisor->id,
    ));
}

it('voids the captured cash payment, reopens the paid order, restores the balance, and drops expected cash', function (): void {
    $payment = t5TakeCash($this);
    expect(Order::findOrFail($this->order->id)->status)->toBe(OrderStatus::Closed);

    $before = app(ShiftTotals::class)->expectedCashCents($this->shift->fresh());
    expect($before)->toBe(20000 + 5000);

    $voided = t5VoidPayment($this, $payment->id);

    expect($voided->status)->toBe('voided')
        ->and($voided->amount_cents)->toBe(5000);

    $order = Order::findOrFail($this->order->id);
    expect($order->status)->toBe(OrderStatus::Open)
        ->and($order->paid_cents)->toBe(0)
        ->and($order->closed_at)->toBeNull()
        ->and($order->closed_by)->toBeNull()
        ->and($order->version)->toBe($payment->order->version + 1);

    // A voided payment drops out of ShiftTotals' captured-only filter — expected cash
    // falls back to just the opening float.
    $after = app(ShiftTotals::class)->expectedCashCents($this->shift->fresh());
    expect($after)->toBe(20000);

    $this->assertDatabaseHas('audit_log', ['action' => 'payment.void', 'entity_id' => $payment->id]);
});

it('refuses a double void', function (): void {
    $payment = t5TakeCash($this);
    t5VoidPayment($this, $payment->id);

    $this->postJson("/api/v1/payments/{$payment->id}/void", ['reason' => 'Refund'],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'payment_already_voided');
});

it('refuses to void a payment once its shift has closed', function (): void {
    $payment = t5TakeCash($this);

    // Close as the shift's own opener — CloseShift only blocks a *different* cashier
    // closing someone else's shift, not the opener themselves.
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $this->shift->id,
        registerId: $this->register->id,
        countedCashCents: app(ShiftTotals::class)->expectedCashCents($this->shift->fresh()),
        note: null,
        actorId: $this->cashier->id,
    ));

    $this->postJson("/api/v1/payments/{$payment->id}/void", ['reason' => 'Refund'],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'payment_shift_closed');
});

it('is denied to a cashier and allowed to a supervisor over HTTP', function (): void {
    $payment = t5TakeCash($this);
    $url = "/api/v1/payments/{$payment->id}/void";
    $body = ['reason' => 'Refund'];

    $this->postJson($url, $body, staffHeaders($this->register, $this->cashier))
        ->assertStatus(403);

    expect(Payment::findOrFail($payment->id)->status)->toBe('captured');

    $this->postJson($url, $body, staffHeaders($this->register, $this->supervisor))
        ->assertOk()
        ->assertJsonPath('data.payment.status', 'voided')
        ->assertJsonPath('data.order.status', 'open');
});

it('requires a reason', function (): void {
    $payment = t5TakeCash($this);

    $this->postJson("/api/v1/payments/{$payment->id}/void", [],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(400);
});
