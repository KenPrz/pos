<?php
// backend/tests/Feature/Day/OpenShiftDayClosedTest.php
declare(strict_types=1);

use App\Actions\Admin\Day\ReopenBusinessDay;
use App\Actions\Admin\Day\ReopenBusinessDayInput;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\DayClosed;
use App\Models\BusinessDay;
use App\Models\User;

it('blocks opening a shift on a closed business day, and reopen lifts the block', function (): void {
    $location = provisionedLocation(['code' => 'DC', 'timezone' => 'Asia/Manila']);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);
    $admin = User::factory()->create(['is_admin' => true]);
    $today = now($location->timezone)->toDateString();

    BusinessDay::create([
        'location_id' => $location->id, 'business_date' => $today, 'closed_by' => $admin->id,
        'gross_sales_cents' => 0, 'refunds_cents' => 0, 'net_sales_cents' => 0, 'tax_cents' => 0,
        'expected_cash_cents' => 0, 'counted_cash_cents' => 0, 'variance_cents' => 0, 'shift_count' => 0,
        'deposit_cents' => 0, 'checklist' => [], 'note' => null,
    ]);

    expect(fn () => app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    )))->toThrow(DayClosed::class);

    app(ReopenBusinessDay::class)->execute(new ReopenBusinessDayInput(
        locationId: $location->id, businessDate: $today, reason: 'late sale', actorId: $admin->id,
    ));

    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    expect($shift->closed_at)->toBeNull();
});
