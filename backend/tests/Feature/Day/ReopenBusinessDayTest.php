<?php
// backend/tests/Feature/Day/ReopenBusinessDayTest.php
declare(strict_types=1);

use App\Actions\Admin\Day\ReopenBusinessDay;
use App\Actions\Admin\Day\ReopenBusinessDayInput;
use App\Exceptions\Domain\DayNotClosed;
use App\Models\BusinessDay;
use App\Models\User;

function reopenRow(string $locationId, string $actorId): BusinessDay
{
    return BusinessDay::create([
        'location_id' => $locationId, 'business_date' => '2026-07-23', 'closed_by' => $actorId,
        'gross_sales_cents' => 0, 'refunds_cents' => 0, 'net_sales_cents' => 0, 'tax_cents' => 0,
        'expected_cash_cents' => 0, 'counted_cash_cents' => 0, 'variance_cents' => 0, 'shift_count' => 0,
        'deposit_cents' => 0, 'checklist' => [], 'note' => null,
    ]);
}

it('reopens a closed day, recording who and why', function (): void {
    $location = provisionedLocation(['code' => 'RO']);
    $admin = User::factory()->create(['is_admin' => true]);
    reopenRow($location->id, $admin->id);

    $row = app(ReopenBusinessDay::class)->execute(new ReopenBusinessDayInput(
        locationId: $location->id, businessDate: '2026-07-23', reason: 'miscount', actorId: $admin->id,
    ));

    expect($row->reopened_at)->not->toBeNull()
        ->and($row->reopened_by)->toBe($admin->id);
});

it('rejects reopening a day that was never closed', function (): void {
    $location = provisionedLocation(['code' => 'RN']);
    $admin = User::factory()->create(['is_admin' => true]);

    expect(fn () => app(ReopenBusinessDay::class)->execute(new ReopenBusinessDayInput(
        locationId: $location->id, businessDate: '2026-07-23', reason: 'x', actorId: $admin->id,
    )))->toThrow(DayNotClosed::class);
});
