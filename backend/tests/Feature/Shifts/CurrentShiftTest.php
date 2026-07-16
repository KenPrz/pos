<?php

// tests/Feature/Shifts/CurrentShiftTest.php
declare(strict_types=1);

use App\Actions\Shifts\GetCurrentShift;
use App\Domain\Rbac\Roles;
use App\Models\Shift;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
});

it('404s when no shift is open', function (): void {
    expect(fn () => app(GetCurrentShift::class)->execute($this->register->id))
        ->toThrow(ModelNotFoundException::class);
});

it('returns the open shift with expected cash and a sales summary', function (): void {
    Shift::factory()->create(['register_id' => $this->register->id, 'opening_float_cents' => 10000]);

    $this->getJson('/api/v1/shifts/current', staffHeaders($this->register, $this->cashier))
        ->assertOk()
        ->assertJsonPath('data.expected_cash_cents', 10000)
        ->assertJsonPath('data.sales_summary.orders_closed', 0)
        ->assertJsonPath('data.shift.opening_float_cents', 10000);
});
