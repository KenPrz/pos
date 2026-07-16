<?php

// tests/Feature/Shifts/CashMovementTest.php
declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Actions\Shifts\RecordCashMovement;
use App\Actions\Shifts\RecordCashMovementInput;
use App\Domain\Rbac\Roles;
use App\Domain\Shifts\ShiftTotals;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->shift = Shift::factory()->create([
        'register_id' => $this->register->id,
        'opened_by' => $this->cashier->id,
        'opening_float_cents' => 10000,
    ]);
});

function t10Movement(object $t, Shift $shift, string $kind, int $amountCents, string $reason, string $actorId): object
{
    return app(RecordCashMovement::class)->execute(new RecordCashMovementInput(
        shiftId: $shift->id,
        registerId: $shift->register_id,
        kind: $kind,
        amountCents: $amountCents,
        reason: $reason,
        actorId: $actorId,
    ));
}

it('drops expected cash by the payout amount and audits it', function (): void {
    $before = app(ShiftTotals::class)->expectedCashCents($this->shift->fresh());

    $movement = t10Movement($this, $this->shift, 'payout', 1500, 'Window cleaner', $this->supervisor->id);

    $after = app(ShiftTotals::class)->expectedCashCents($this->shift->fresh());

    expect($before - $after)->toBe(1500)
        ->and($movement->kind)->toBe('payout')
        ->and($movement->amount_cents)->toBe(1500)
        ->and($movement->reason)->toBe('Window cleaner');

    $this->assertDatabaseHas('audit_log', [
        'action' => 'shift.cash_movement',
        'entity_id' => $this->shift->id,
    ]);
    $this->assertDatabaseHas('cash_movements', [
        'shift_id' => $this->shift->id,
        'kind' => 'payout',
        'amount_cents' => 1500,
        'reason' => 'Window cleaner',
    ]);
});

it('nets paid_in, payout and drop exactly — closes the M3 ShiftTotals gap', function (): void {
    t10Movement($this, $this->shift, 'paid_in', 2000, 'Change fund top-up', $this->supervisor->id);
    t10Movement($this, $this->shift, 'payout', 500, 'Supplies', $this->supervisor->id);
    t10Movement($this, $this->shift, 'drop', 700, 'Safe drop', $this->supervisor->id);

    $expected = app(ShiftTotals::class)->expectedCashCents($this->shift->fresh());

    // float 10000 + 2000 paid_in - 500 payout - 700 drop
    expect($expected)->toBe(10000 + 2000 - 500 - 700);
});

it('rejects a non-positive amount', function (): void {
    $this->postJson("/api/v1/shifts/{$this->shift->id}/cash-movements", [
        'kind' => 'payout', 'amount_cents' => 0, 'reason' => 'Test',
    ], staffHeaders($this->register, $this->supervisor))->assertStatus(400);
});

it('rejects a missing reason', function (): void {
    $this->postJson("/api/v1/shifts/{$this->shift->id}/cash-movements", [
        'kind' => 'payout', 'amount_cents' => 500,
    ], staffHeaders($this->register, $this->supervisor))->assertStatus(400);
});

it('refuses a cash movement on a closed shift', function (): void {
    app(CloseShift::class)->execute(new CloseShiftInput(
        $this->shift->id, $this->register->id, 10000, null, $this->cashier->id,
    ));

    $this->postJson("/api/v1/shifts/{$this->shift->id}/cash-movements", [
        'kind' => 'payout', 'amount_cents' => 500, 'reason' => 'Test',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'shift_already_closed');
});

it('forbids a cashier and allows a supervisor over HTTP', function (): void {
    $this->postJson("/api/v1/shifts/{$this->shift->id}/cash-movements", [
        'kind' => 'payout', 'amount_cents' => 500, 'reason' => 'Test',
    ], staffHeaders($this->register, $this->cashier))
        ->assertStatus(403);

    $this->postJson("/api/v1/shifts/{$this->shift->id}/cash-movements", [
        'kind' => 'payout', 'amount_cents' => 500, 'reason' => 'Test',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertCreated()
        ->assertJsonPath('data.cash_movement.kind', 'payout')
        ->assertJsonPath('data.cash_movement.amount_cents', 500)
        ->assertJsonPath('data.cash_movement.reason', 'Test')
        ->assertJsonPath('data.cash_movement.shift_id', $this->shift->id);
});

it('folds movements into the stored expected cash at close — variance 0 when counted matches', function (): void {
    $this->postJson("/api/v1/shifts/{$this->shift->id}/cash-movements", [
        'kind' => 'payout', 'amount_cents' => 1500, 'reason' => 'Window cleaner',
    ], staffHeaders($this->register, $this->supervisor))->assertCreated();

    $expected = app(ShiftTotals::class)->expectedCashCents($this->shift->fresh());

    $closed = app(CloseShift::class)->execute(new CloseShiftInput(
        $this->shift->id, $this->register->id, $expected, null, $this->cashier->id,
    ));

    expect($closed->variance_cents)->toBe(0)
        ->and($closed->expected_cash_cents)->toBe($expected);
});
