<?php

declare(strict_types=1);

use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Actions\Payments\VoidPayment;
use App\Actions\Payments\VoidPaymentInput;
use App\Domain\Money\Money;
use App\Domain\Payments\ExternalCardDriver;
use App\Domain\Payments\PaymentIntent;
use App\Domain\Rbac\Roles;
use App\Domain\Shifts\ShiftTotals;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->shift = Shift::factory()->create(['register_id' => $this->register->id]);
    $this->order = Order::factory()->create([
        'location_id' => $this->location->id,
        'register_id' => $this->register->id,
        'shift_id' => $this->shift->id,
        'opened_by' => $this->cashier->id,
        'total_cents' => 5000,
        'subtotal_cents' => 5000,
    ]);
});

// Pest file-scoped functions collide across test files if two share a name — namespaced
// per task to keep this file free to move or be copied.
function t9cTakeCash(object $t, int $amount, ?int $tendered = null): Payment
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $t->order->id,
        registerId: $t->register->id,
        driver: 'cash',
        amountCents: $amount,
        tenderedCents: $tendered,
        reference: null,
        expectedVersion: Order::findOrFail($t->order->id)->version,
        actorId: $t->cashier->id,
    ));
}

function t9cTakeCard(object $t, int $amount, ?string $reference = 'auth 004321'): Payment
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $t->order->id,
        registerId: $t->register->id,
        driver: 'external_card',
        amountCents: $amount,
        tenderedCents: null,
        reference: $reference,
        expectedVersion: Order::findOrFail($t->order->id)->version,
        actorId: $t->cashier->id,
    ));
}

function t9cVoidPayment(object $t, string $paymentId): Payment
{
    return app(VoidPayment::class)->execute(new VoidPaymentInput(
        paymentId: $paymentId,
        registerId: $t->register->id,
        reason: 'Mis-keyed card',
        actorId: $t->supervisor->id,
    ));
}

it('a card payment for the full total closes the order and stores the reference, not a tender', function (): void {
    $payment = t9cTakeCard($this, 5000, 'auth 004321');

    expect($payment->status)->toBe('captured')
        ->and($payment->driver)->toBe('external_card')
        ->and($payment->amount_cents)->toBe(5000)
        ->and($payment->tendered_cents)->toBeNull()
        ->and($payment->change_cents)->toBeNull()
        ->and($payment->reference)->toBe('auth 004321');

    $order = Order::findOrFail($this->order->id);
    expect($order->status)->toBe(OrderStatus::Closed)
        ->and($order->paid_cents)->toBe(5000)
        ->and($order->closed_at)->not->toBeNull();
});

it('a split cash + card payment sums to close the order, and ShiftTotals counts only the cash leg', function (): void {
    t9cTakeCash($this, 2000, tendered: 2000);
    expect(Order::findOrFail($this->order->id)->status)->toBe(OrderStatus::Open);

    $card = t9cTakeCard($this, 3000, 'auth 009988');

    $order = Order::findOrFail($this->order->id);
    expect($order->status)->toBe(OrderStatus::Closed)
        ->and($order->paid_cents)->toBe(5000)
        ->and($card->driver)->toBe('external_card');

    $summary = app(ShiftTotals::class)->salesSummary($this->shift->fresh());
    expect($summary['cash_cents'])->toBe(2000)
        ->and($summary['total_cents'])->toBe(5000);

    // expectedCashCents (the drawer count) only ever moves for the cash leg.
    expect(app(ShiftTotals::class)->expectedCashCents($this->shift->fresh()))
        ->toBe($this->shift->opening_float_cents + 2000);
});

it('POST /refunds still rejects driver external_card — validation, not the driver, is the gate', function (): void {
    t9cTakeCard($this, 5000, 'auth 004321');
    $order = Order::findOrFail($this->order->id);

    // This order has no lines (built directly, like TakePaymentTest's chassis), so the
    // request never gets far enough to look for one — the `in:cash` rule on `driver`
    // fails first, which is exactly the point: it's a 400 before any lookup happens.
    $body = [
        'original_order_id' => $order->id,
        'driver' => 'external_card',
        'reason' => 'Customer return',
        'lines' => [['original_order_line_id' => (string) Str::uuid(), 'qty' => '1', 'restock' => false]],
    ];

    $this->postJson('/api/v1/refunds', $body, staffHeaders($this->register, $this->supervisor)
        + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(400);
});

it('VoidPayment voids a card payment same-shift, reopening the order', function (): void {
    $payment = t9cTakeCard($this, 5000, 'auth 004321');
    expect(Order::findOrFail($this->order->id)->status)->toBe(OrderStatus::Closed);

    $voided = t9cVoidPayment($this, $payment->id);

    expect($voided->status)->toBe('voided')
        ->and($voided->driver)->toBe('external_card')
        ->and($voided->amount_cents)->toBe(5000);

    $order = Order::findOrFail($this->order->id);
    expect($order->status)->toBe(OrderStatus::Open)
        ->and($order->paid_cents)->toBe(0)
        ->and($order->closed_at)->toBeNull();
});

it('the driver: refundable false, async false, and refund() is unreachable', function (): void {
    $driver = new ExternalCardDriver;

    expect($driver->code())->toBe('external_card');

    $capabilities = $driver->capabilities();
    expect($capabilities->refundable)->toBeFalse()
        ->and($capabilities->async)->toBeFalse();

    $result = $driver->authorize(new PaymentIntent(
        amount: Money::fromCents(1500), tendered: null, reference: 'auth 004321',
    ));
    expect($result->status)->toBe('captured')
        ->and($result->tender)->toBeNull()
        ->and($result->reference)->toBe('auth 004321');

    $payment = new Payment(['status' => 'captured', 'driver' => 'external_card', 'amount_cents' => 1500]);

    $captured = $driver->capture($payment);
    expect($captured->status)->toBe('captured')
        ->and($captured->tender)->toBeNull()
        ->and($captured->reference)->toBeNull();

    $voided = $driver->void($payment);
    expect($voided->status)->toBe('voided')
        ->and($voided->tender)->toBeNull()
        ->and($voided->reference)->toBeNull();

    expect(fn () => $driver->refund($payment, Money::fromCents(1500)))
        ->toThrow(LogicException::class);
});
