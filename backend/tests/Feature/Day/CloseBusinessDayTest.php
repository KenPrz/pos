<?php
// backend/tests/Feature/Day/CloseBusinessDayTest.php
declare(strict_types=1);

use App\Actions\Admin\Day\CloseBusinessDay;
use App\Actions\Admin\Day\CloseBusinessDayInput;
use App\Actions\Admin\Day\ReopenBusinessDay;
use App\Actions\Admin\Day\ReopenBusinessDayInput;
use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\DayAlreadyClosed;
use App\Exceptions\Domain\DayHasOpenOrders;
use App\Exceptions\Domain\DayHasOpenShifts;
use App\Models\BusinessDay;
use App\Models\User;

function closeDayFixture(): array
{
    $location = provisionedLocation(['code' => 'CD', 'timezone' => 'Asia/Manila']);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);
    $admin = User::factory()->create(['is_admin' => true]);

    return [$location, $register, $cashier, $admin];
}

function closeInput(object $location, string $actorId): CloseBusinessDayInput
{
    return new CloseBusinessDayInput(
        locationId: $location->id,
        businessDate: now($location->timezone)->toDateString(),
        depositCents: 0,
        checklist: ['cash_drop_confirmed' => true, 'spoilage_note' => '', 'next_day_note' => ''],
        note: null,
        actorId: $actorId,
    );
}

it('closes a day when every shift is closed and no order is open', function (): void {
    [$location, $register, $cashier, $admin] = closeDayFixture();
    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 10000, note: null, actorId: $cashier->id,
    ));

    $row = app(CloseBusinessDay::class)->execute(closeInput($location, $admin->id));

    expect($row)->toBeInstanceOf(BusinessDay::class)
        ->and($row->shift_count)->toBe(1)
        ->and($row->expected_cash_cents)->toBe(10000)
        ->and($row->reopened_at)->toBeNull();
});

it('refuses to close while a shift is still open', function (): void {
    [$location, $register, $cashier, $admin] = closeDayFixture();
    app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));

    expect(fn () => app(CloseBusinessDay::class)->execute(closeInput($location, $admin->id)))
        ->toThrow(DayHasOpenShifts::class);
});

it('refuses to close while an order is open', function (): void {
    // Proves the open-orders guard in isolation: no shifts at all (so the open-shifts
    // guard can't be what's firing), one order left open for today.
    [$location, $register, $cashier, $admin] = closeDayFixture();
    $order = \App\Models\Order::factory()->create([
        'location_id' => $location->id, 'register_id' => $register->id,
        'business_date' => now($location->timezone)->toDateString(), 'status' => 'open',
        'opened_by' => $cashier->id,
    ]);

    expect(fn () => app(CloseBusinessDay::class)->execute(closeInput($location, $admin->id)))
        ->toThrow(DayHasOpenOrders::class);
});

it('refuses to re-close a day that is already closed and never reopened', function (): void {
    [$location, $register, $cashier, $admin] = closeDayFixture();
    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 10000, note: null, actorId: $cashier->id,
    ));
    app(CloseBusinessDay::class)->execute(closeInput($location, $admin->id));

    expect(fn () => app(CloseBusinessDay::class)->execute(closeInput($location, $admin->id)))
        ->toThrow(DayAlreadyClosed::class);
});

it('re-snapshots and clears reopened_at/reopened_by when closing again after a reopen', function (): void {
    [$location, $register, $cashier, $admin] = closeDayFixture();
    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 10000, note: null, actorId: $cashier->id,
    ));
    $firstClose = app(CloseBusinessDay::class)->execute(closeInput($location, $admin->id));

    app(ReopenBusinessDay::class)->execute(new ReopenBusinessDayInput(
        locationId: $location->id, businessDate: $firstClose->business_date, reason: 'miscount', actorId: $admin->id,
    ));

    $secondClose = app(CloseBusinessDay::class)->execute(closeInput($location, $admin->id));

    expect($secondClose->id)->toBe($firstClose->id)
        ->and($secondClose->reopened_at)->toBeNull()
        ->and($secondClose->reopened_by)->toBeNull()
        ->and($secondClose->shift_count)->toBe(1)
        ->and($secondClose->expected_cash_cents)->toBe(10000);
});
