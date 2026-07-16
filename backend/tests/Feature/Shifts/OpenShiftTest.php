<?php

// tests/Feature/Shifts/OpenShiftTest.php
declare(strict_types=1);

use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\ShiftAlreadyOpen;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
});

it('opens a shift with a float and audits it', function (): void {
    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $this->register->id,
        openingFloatCents: 20000,
        actorId: $this->cashier->id,
    ));

    expect($shift->opening_float_cents)->toBe(20000)->and($shift->closed_at)->toBeNull();
    $this->assertDatabaseHas('audit_log', ['action' => 'shift.open', 'entity_id' => $shift->id]);
});

it('refuses a second open shift on the same register', function (): void {
    $input = new OpenShiftInput($this->register->id, 20000, $this->cashier->id);
    app(OpenShift::class)->execute($input);

    expect(fn () => app(OpenShift::class)->execute($input))->toThrow(ShiftAlreadyOpen::class);
});

it('opens over HTTP with the right envelope', function (): void {
    $this->postJson('/api/v1/shifts/open', ['opening_float_cents' => 15000], staffHeaders($this->register, $this->cashier))
        ->assertCreated()
        ->assertJsonPath('data.opening_float_cents', 15000)
        ->assertJsonPath('data.register_id', $this->register->id);
});
