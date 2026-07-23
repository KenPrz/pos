<?php
// backend/tests/Feature/Day/GetBusinessDayTest.php
declare(strict_types=1);

use App\Actions\Admin\Day\CloseBusinessDay;
use App\Actions\Admin\Day\CloseBusinessDayInput;
use App\Actions\Admin\Day\GetBusinessDay;
use App\Actions\Admin\Day\GetBusinessDayInput;
use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\Roles;
use App\Models\BusinessDay;
use App\Models\User;

it('reports an open shift as a blocker and marks the day not closable', function (): void {
    $location = provisionedLocation(['code' => 'GB', 'timezone' => 'Asia/Manila']);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);
    app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 5000, actorId: $cashier->id,
    ));

    $view = app(GetBusinessDay::class)->execute(new GetBusinessDayInput(
        locationId: $location->id, businessDate: now($location->timezone)->toDateString(),
    ));

    expect($view->closable)->toBeFalse()
        ->and($view->open_shifts)->toHaveCount(1)
        ->and($view->record)->toBeNull();
});

it('reports a closed, reconciled day as closable with its record', function (): void {
    $location = provisionedLocation(['code' => 'GC', 'timezone' => 'Asia/Manila']);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);
    $admin = User::factory()->create(['is_admin' => true]);

    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 10000, note: null, actorId: $cashier->id,
    ));

    $businessDate = now($location->timezone)->toDateString();

    app(CloseBusinessDay::class)->execute(new CloseBusinessDayInput(
        locationId: $location->id,
        businessDate: $businessDate,
        depositCents: 0,
        checklist: ['cash_drop_confirmed' => true, 'spoilage_note' => '', 'next_day_note' => ''],
        note: null,
        actorId: $admin->id,
    ));

    $view = app(GetBusinessDay::class)->execute(new GetBusinessDayInput(
        locationId: $location->id, businessDate: $businessDate,
    ));

    expect($view->closable)->toBeTrue()
        ->and($view->open_shifts)->toHaveCount(0)
        ->and($view->record)->toBeInstanceOf(BusinessDay::class);
});

it('counts a closed shift with an unapproved nonzero variance', function (): void {
    $location = provisionedLocation(['code' => 'GV', 'timezone' => 'Asia/Manila']);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);

    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    // Counted differs from the expected (opening float, no sales) -> nonzero variance,
    // left unapproved.
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 9500, note: null, actorId: $cashier->id,
    ));

    $view = app(GetBusinessDay::class)->execute(new GetBusinessDayInput(
        locationId: $location->id, businessDate: now($location->timezone)->toDateString(),
    ));

    expect($view->unapproved_variance_count)->toBe(1)
        ->and($view->closable)->toBeTrue();
});
