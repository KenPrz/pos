<?php

// tests/Feature/Shifts/CloseShiftTest.php
declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\ShiftAlreadyClosed;
use App\Exceptions\Domain\ShiftHasOpenOrders;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->shift = Shift::factory()->create([
        'register_id' => $this->register->id,
        'opened_by' => $this->cashier->id,
        'opening_float_cents' => 10000,
    ]);
});

it('computes variance = counted − (float + cash sales)', function (): void {
    $order = Order::factory()->forRegister($this->register)->create([
        'shift_id' => $this->shift->id, 'status' => 'closed', 'total_cents' => 4353, 'paid_cents' => 4353,
    ]);
    Payment::create([
        'order_id' => $order->id, 'shift_id' => $this->shift->id, 'driver' => 'cash',
        'status' => 'captured', 'amount_cents' => 4353, 'tendered_cents' => 5000,
        'change_cents' => 647, 'user_id' => $this->cashier->id,
        'created_at' => now(), 'captured_at' => now(),
    ]);

    $closed = app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $this->shift->id, registerId: $this->register->id,
        countedCashCents: 14300, note: null, actorId: $this->cashier->id,
    ));

    // expected = 10000 float + 4353 cash sales = 14353; counted 14300 → variance −53
    expect($closed->expected_cash_cents)->toBe(14353)
        ->and($closed->variance_cents)->toBe(-53)
        ->and($closed->closed_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'shift.close', 'entity_id' => $closed->id]);
});

it('refuses to close with open orders, listing them', function (): void {
    Order::factory()->forRegister($this->register)->create(['shift_id' => $this->shift->id, 'status' => 'open']);

    expect(fn () => app(CloseShift::class)->execute(new CloseShiftInput(
        $this->shift->id, $this->register->id, 10000, null, $this->cashier->id,
    )))->toThrow(ShiftHasOpenOrders::class);
});

it('refuses a double close', function (): void {
    $input = new CloseShiftInput($this->shift->id, $this->register->id, 10000, null, $this->cashier->id);
    app(CloseShift::class)->execute($input);

    expect(fn () => app(CloseShift::class)->execute($input))->toThrow(ShiftAlreadyClosed::class);
});

it('flags variance beyond the threshold for approval, but still closes', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['Idempotency-Key' => (string) Str::uuid()];

    // threshold is 500 (config/pos.php); short by 600
    $this->postJson("/api/v1/shifts/{$this->shift->id}/close", ['counted_cash_cents' => 9400], $headers)
        ->assertOk()
        ->assertJsonPath('data.variance_cents', -600)
        ->assertJsonPath('data.requires_approval', true);

    expect(Shift::findOrFail($this->shift->id)->closed_at)->not->toBeNull();
});

it('requires an Idempotency-Key over HTTP', function (): void {
    // docs/03-api.md: validation_failed is 400, not 422 — 422 is reserved for
    // domain-semantic conflicts (payment_exceeds_balance and the like).
    $this->postJson("/api/v1/shifts/{$this->shift->id}/close", ['counted_cash_cents' => 10000], staffHeaders($this->register, $this->cashier))
        ->assertStatus(400);
});

it('ends the staff sessions on that register at close', function (): void {
    $headers = staffHeaders($this->register, $this->cashier) + ['Idempotency-Key' => (string) Str::uuid()];

    $this->postJson("/api/v1/shifts/{$this->shift->id}/close", ['counted_cash_cents' => 10000], $headers)->assertOk();

    // The same staff token is now dead; the device token still works.
    $this->getJson('/api/v1/shifts/current', $headers)->assertStatus(401);
});
