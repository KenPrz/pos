<?php

declare(strict_types=1);

use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\InsufficientTender;
use App\Exceptions\Domain\NoOpenShift;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\PaymentExceedsBalance;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
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

function t9TakeCash(object $t, int $amount, ?int $tendered = null, ?int $version = null): Payment
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $t->order->id,
        registerId: $t->register->id,
        driver: 'cash',
        amountCents: $amount,
        tenderedCents: $tendered,
        reference: null,
        expectedVersion: $version ?? Order::findOrFail($t->order->id)->version,
        actorId: $t->cashier->id,
    ));
}

it('captures cash, computes change in integers, and auto-closes at paid in full', function (): void {
    $payment = t9TakeCash($this, 5000, tendered: 6000);

    expect($payment->status)->toBe('captured')
        ->and($payment->amount_cents)->toBe(5000)
        ->and($payment->tendered_cents)->toBe(6000)
        ->and($payment->change_cents)->toBe(1000)
        ->and($payment->shift_id)->toBe($this->shift->id);

    $order = $payment->order;
    expect($order->paid_cents)->toBe(5000)
        ->and($order->status)->toBe(OrderStatus::Closed)
        ->and($order->closed_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'payment.take', 'entity_id' => $payment->id]);
});

it('a partial payment leaves the order open — splitting is just several payments', function (): void {
    t9TakeCash($this, 2000);
    expect(Order::findOrFail($this->order->id)->status)->toBe(OrderStatus::Open);

    $second = t9TakeCash($this, 3000);
    expect($second->order->status)->toBe(OrderStatus::Closed)
        ->and($second->order->paid_cents)->toBe(5000);
});

it('overpaying is an error, not change', function (): void {
    expect(fn () => t9TakeCash($this, 6000))->toThrow(PaymentExceedsBalance::class);
});

it('tendering less than the amount applied is insufficient_tender', function (): void {
    expect(fn () => t9TakeCash($this, 5000, tendered: 4000))->toThrow(InsufficientTender::class);
});

it('requires an open shift on the register taking the payment', function (): void {
    $this->shift->forceFill(['closed_at' => now(), 'counted_cash_cents' => 0])->save();

    expect(fn () => t9TakeCash($this, 5000))->toThrow(NoOpenShift::class);
});

it('rejects payment on a closed order', function (): void {
    t9TakeCash($this, 5000);

    expect(fn () => t9TakeCash($this, 1000))->toThrow(OrderClosed::class);
});

it('a replayed idempotency key charges once', function (): void {
    $headers = staffHeaders($this->register, $this->cashier)
        + ['If-Match' => '0', 'Idempotency-Key' => (string) Str::uuid()];
    $body = ['driver' => 'cash', 'amount_cents' => 5000, 'tendered_cents' => 6000];

    $first = $this->postJson("/api/v1/orders/{$this->order->id}/payments", $body, $headers);
    $second = $this->postJson("/api/v1/orders/{$this->order->id}/payments", $body, $headers);

    $first->assertCreated()
        ->assertJsonPath('data.payment.change_cents', 1000)
        ->assertJsonPath('data.order.status', 'closed');
    // toEqual, not toBe: the replayed body round-trips through the `idempotency_keys`
    // table's jsonb column (EnsureIdempotency, Task 2), and jsonb does not preserve
    // object key order — Postgres re-serializes keys by byte length then lexically.
    // The invariant under test is "identical data," not "identical key order."
    expect($second->json())->toEqual($first->json())
        ->and(Payment::where('order_id', $this->order->id)->count())->toBe(1);
});

it('requires the Idempotency-Key header', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/payments",
        ['driver' => 'cash', 'amount_cents' => 5000], $headers)->assertStatus(400);
});
