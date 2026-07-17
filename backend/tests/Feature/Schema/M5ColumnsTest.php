<?php

// backend/tests/Feature/Schema/M5ColumnsTest.php
declare(strict_types=1);

use App\Actions\Auth\SetStaffPin;
use App\Actions\Auth\SetStaffPinInput;
use App\Domain\Rbac\Roles;
use App\Models\Shift;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('defaults registers.mode to retail and enforces the check constraint', function (): void {
    $register = registerAt(provisionedLocation());
    expect($register->refresh()->mode)->toBe('retail');

    expect(fn () => DB::statement(
        "update registers set mode = 'disco' where id = ?", [$register->id]
    ))->toThrow(QueryException::class);
});

it('stores a variance approval on shifts', function (): void {
    $location = provisionedLocation();
    $register = registerAt($location);
    $supervisor = staffWithRole($location, Roles::SUPERVISOR);
    $shift = Shift::factory()->create(['register_id' => $register->id, 'closed_at' => now(), 'counted_cash_cents' => 0]);

    $shift->forceFill(['variance_approved_by' => $supervisor->id, 'variance_approved_at' => now()])->save();
    expect($shift->refresh()->variance_approved_by)->toBe($supervisor->id);
});

it('returns the register with its mode on staff login', function (): void {
    $location = provisionedLocation();
    $register = registerAt($location);
    $register->forceFill(['mode' => 'food'])->save();
    $cashier = staffWithRole($location, Roles::CASHIER);
    app(SetStaffPin::class)->execute(new SetStaffPinInput(userId: $cashier->id, pin: '4321', actorId: $cashier->id));

    $token = $register->createToken('device')->plainTextToken;
    $this->postJson('/api/v1/staff/login', ['pin' => '4321'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('data.register.id', $register->id)
        ->assertJsonPath('data.register.mode', 'food');
});
