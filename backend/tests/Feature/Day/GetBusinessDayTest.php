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
use Illuminate\Support\Facades\DB;

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

it('counts a closed shift with an unapproved variance over the approval threshold', function (): void {
    $location = provisionedLocation(['code' => 'GV', 'timezone' => 'Asia/Manila']);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);

    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    // Counted differs from expected (opening float, no sales) by 1000, over the default
    // 500-cent threshold -> approvable but left unapproved.
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 9000, note: null, actorId: $cashier->id,
    ));

    $view = app(GetBusinessDay::class)->execute(new GetBusinessDayInput(
        locationId: $location->id, businessDate: now($location->timezone)->toDateString(),
    ));

    expect($view->unapproved_variance_count)->toBe(1)
        ->and($view->closable)->toBeTrue();
});

it('does not count a closed shift whose variance is under the approval threshold', function (): void {
    $location = provisionedLocation(['code' => 'GU', 'timezone' => 'Asia/Manila']);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);

    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    // Counted differs from expected by 100, under the default 500-cent threshold -> not
    // even approvable (ApproveVariance would throw under_threshold), so it must not warn.
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 9900, note: null, actorId: $cashier->id,
    ));

    $view = app(GetBusinessDay::class)->execute(new GetBusinessDayInput(
        locationId: $location->id, businessDate: now($location->timezone)->toDateString(),
    ));

    expect($view->unapproved_variance_count)->toBe(0)
        ->and($view->closable)->toBeTrue();
});

it('reads a closed day\'s totals from the frozen record, not a live recomputation', function (): void {
    $location = provisionedLocation(['code' => 'GF', 'timezone' => 'Asia/Manila']);
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

    // Simulate the ledger changing after close (a live recomputation would pick this up;
    // the frozen record must not) by editing the persisted row directly to a sentinel
    // that no live query over the current ledger state would ever produce.
    DB::table('business_days')
        ->where('location_id', $location->id)->where('business_date', $businessDate)
        ->update(['gross_sales_cents' => 123456, 'net_sales_cents' => 123456]);

    $view = app(GetBusinessDay::class)->execute(new GetBusinessDayInput(
        locationId: $location->id, businessDate: $businessDate,
    ));

    expect($view->snapshot->grossSalesCents)->toBe(123456)
        ->and($view->snapshot->netSalesCents)->toBe(123456);
});
