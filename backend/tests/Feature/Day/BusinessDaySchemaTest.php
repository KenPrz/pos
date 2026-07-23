<?php
// backend/tests/Feature/Day/BusinessDaySchemaTest.php
declare(strict_types=1);

use App\Models\BusinessDay;
use App\Models\User;
use Illuminate\Database\QueryException;

function daysLocation(): string
{
    return provisionedLocation(['code' => 'BD'])->id;
}

it('stores a business-day close row', function (): void {
    $locationId = daysLocation();
    $actor = User::factory()->create(['is_admin' => true]);

    $row = BusinessDay::create([
        'location_id' => $locationId,
        'business_date' => '2026-07-23',
        'closed_by' => $actor->id,
        'gross_sales_cents' => 100000,
        'refunds_cents' => 0,
        'net_sales_cents' => 100000,
        'tax_cents' => 10714,
        'expected_cash_cents' => 50000,
        'counted_cash_cents' => 49900,
        'variance_cents' => -100,
        'shift_count' => 2,
        'deposit_cents' => 40000,
        'checklist' => ['cash_drop_confirmed' => true, 'spoilage_note' => '', 'next_day_note' => ''],
        'note' => null,
    ]);

    expect($row->refresh()->reopened_at)->toBeNull()
        ->and($row->checklist['cash_drop_confirmed'])->toBeTrue();
});

it('rejects a second close for the same location and date', function (): void {
    $locationId = daysLocation();
    $actor = User::factory()->create(['is_admin' => true]);
    $base = [
        'location_id' => $locationId, 'business_date' => '2026-07-23', 'closed_by' => $actor->id,
        'gross_sales_cents' => 0, 'refunds_cents' => 0, 'net_sales_cents' => 0, 'tax_cents' => 0,
        'expected_cash_cents' => 0, 'counted_cash_cents' => 0, 'variance_cents' => 0, 'shift_count' => 0,
        'deposit_cents' => 0, 'checklist' => [], 'note' => null,
    ];
    BusinessDay::create($base);

    expect(fn () => BusinessDay::create($base))->toThrow(QueryException::class);
});
