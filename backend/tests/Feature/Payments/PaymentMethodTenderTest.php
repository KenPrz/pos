<?php

declare(strict_types=1);

use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\PaymentMethodInactive;
use App\Exceptions\Domain\PaymentMethodUnknown;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use App\Models\Shift;

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

function t4pTakeOn(object $t, string $code, ?int $tendered = null, ?string $reference = null): \App\Models\Payment
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $t->order->id,
        registerId: $t->register->id,
        paymentMethodCode: $code,
        amountCents: 5000,
        tenderedCents: $tendered,
        reference: $reference,
        expectedVersion: Order::findOrFail($t->order->id)->version,
        actorId: $t->cashier->id,
    ));
}

it('derives the driver from the method\'s group and snapshots code and name', function (): void {
    $payment = t4pTakeOn($this, 'CASH', tendered: 6000);

    expect($payment->driver)->toBe('cash');            // derived, never sent
    expect($payment->payment_method_code)->toBe('CASH');
    expect($payment->payment_method_name)->toBe('Cash');
    expect($payment->change_cents)->toBe(1000);        // cash driver still computes change
});

it('takes a tender on an admin-created e-wallet method', function (): void {
    // Same driver as CARD, its own group so the Z-report keeps the totals apart.
    $group = PaymentMethodGroup::factory()->create([
        'location_id' => $this->location->id,
        'code' => 'EWALLET', 'name' => 'E-wallets', 'driver' => 'external_card', 'sort_order' => 2,
    ]);
    $method = PaymentMethod::factory()->create([
        'location_id' => $this->location->id, 'group_id' => $group->id,
        'code' => 'GCASH', 'name' => 'GCash',
    ]);

    $payment = t4pTakeOn($this, 'GCASH', reference: '0917 555 0101');

    expect($payment->driver)->toBe('external_card');
    expect($payment->payment_method_id)->toBe($method->id);
    expect($payment->payment_method_code)->toBe('GCASH');
    expect($payment->payment_method_name)->toBe('GCash');
    expect($payment->reference)->toBe('0917 555 0101');
    expect($payment->change_cents)->toBeNull();
});

it('renaming a method never rewrites a past tender', function (): void {
    $payment = t4pTakeOn($this, 'CASH', tendered: 5000);

    PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CASH')->update(['name' => 'Cash (peso)']);

    // The snapshot is why a receipt from last year reprints identically.
    expect($payment->fresh()->payment_method_name)->toBe('Cash');
});

it('refuses a method code that does not exist', function (): void {
    expect(fn () => t4pTakeOn($this, 'MAYA', tendered: 5000))
        ->toThrow(PaymentMethodUnknown::class);
});

it('refuses an archived method', function (): void {
    PaymentMethod::query()->where('location_id', $this->location->id)
        ->where('code', 'CASH')->update(['is_active' => false]);

    expect(fn () => t4pTakeOn($this, 'CASH', tendered: 5000))
        ->toThrow(PaymentMethodInactive::class);
});

it('rejects the method code over HTTP with a 422 envelope', function (): void {
    $headers = staffHeaders($this->register, $this->cashier)
        + ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(), 'If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/payments", [
        'payment_method_code' => 'MAYA', 'amount_cents' => 5000, 'tendered_cents' => 5000,
    ], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'payment_method_unknown');
});

it('returns the method on the take-payment response', function (): void {
    $headers = staffHeaders($this->register, $this->cashier)
        + ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(), 'If-Match' => '0'];

    $this->postJson("/api/v1/orders/{$this->order->id}/payments", [
        'payment_method_code' => 'CASH', 'amount_cents' => 5000, 'tendered_cents' => 6000,
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.payment.payment_method_code', 'CASH')
        ->assertJsonPath('data.payment.payment_method_name', 'Cash')
        ->assertJsonPath('data.payment.driver', 'cash')
        ->assertJsonPath('data.payment.change_cents', 1000);
});

it('still refuses a driver-shaped body', function (): void {
    $headers = staffHeaders($this->register, $this->cashier)
        + ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(), 'If-Match' => '0'];

    // There is ONE way to name a tender, and `driver` is not it anymore. A body that
    // names no tender is MALFORMED, so it is 400 validation_failed — 422 is reserved for
    // structurally-fine requests that are semantically rejected (ApiErrorEnvelope).
    $this->postJson("/api/v1/orders/{$this->order->id}/payments", [
        'driver' => 'cash', 'amount_cents' => 5000, 'tendered_cents' => 5000,
    ], $headers)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});
