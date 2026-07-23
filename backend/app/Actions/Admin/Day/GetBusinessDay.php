<?php
// backend/app/Actions/Admin/Day/GetBusinessDay.php
declare(strict_types=1);

namespace App\Actions\Admin\Day;

use App\Domain\Day\DaySnapshot;
use App\Domain\Day\DayTotals;
use App\Models\BusinessDay;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Everything the End-Of-Day screen needs for one location-date: totals, the close
 * blockers (open shifts / open orders), a non-blocking unapproved-variance count, whether
 * the day is closable, and the close record if one exists. Read-only — while the day is
 * open, `snapshot` is computed live off the ledgers; once a closed record exists, the
 * spec requires the frozen row, not a live recomputation, so `snapshot` is built from
 * that record's own eight columns instead.
 */
final class GetBusinessDay
{
    public function __construct(private readonly DayTotals $totals) {}

    public function execute(GetBusinessDayInput $in): object
    {
        $location = Location::query()->findOrFail($in->locationId);
        $tz = $location->timezone;

        $openShifts = DB::table('shifts as s')
            ->join('registers as r', 'r.id', '=', 's.register_id')
            ->join('users as u', 'u.id', '=', 's.opened_by')
            ->whereNull('s.closed_at')
            ->where('r.location_id', $in->locationId)
            ->orderBy('r.name')
            ->get(['r.id as register_id', 'r.name as register_name', 's.id as shift_id', 'u.name as opened_by_name']);

        $openOrders = (int) DB::table('orders')
            ->where('location_id', $in->locationId)
            ->where('business_date', $in->businessDate)
            ->where('status', 'open')
            ->count();

        // Shifts closed on this local date whose variance is unsigned AND over the
        // location's approval threshold (falling back to the config default the same way
        // ApproveVariance resolves it) — a variance under threshold can never be approved
        // in the first place (ApproveVariance throws `under_threshold`), so counting it
        // here would raise a warning the manager has no way to clear.
        $threshold = (int) ($location->variance_approval_threshold_cents
            ?? config('pos.shifts.variance_approval_threshold_cents'));
        $unapprovedVariance = (int) DB::table('shifts as s')
            ->join('registers as r', 'r.id', '=', 's.register_id')
            ->whereNotNull('s.closed_at')
            ->whereNull('s.variance_approved_at')
            ->whereRaw('(s.closed_at at time zone ?)::date = ?', [$tz, $in->businessDate])
            ->where('r.location_id', $in->locationId)
            ->whereRaw('abs(s.variance_cents) > ?', [$threshold])
            ->count();

        $record = BusinessDay::query()
            ->where('location_id', $in->locationId)
            ->where('business_date', $in->businessDate)
            ->whereNull('reopened_at')
            ->first();

        $snapshot = $record !== null
            ? new DaySnapshot(
                grossSalesCents: (int) $record->gross_sales_cents,
                refundsCents: (int) $record->refunds_cents,
                netSalesCents: (int) $record->net_sales_cents,
                taxCents: (int) $record->tax_cents,
                expectedCashCents: (int) $record->expected_cash_cents,
                countedCashCents: (int) $record->counted_cash_cents,
                varianceCents: (int) $record->variance_cents,
                shiftCount: (int) $record->shift_count,
            )
            : $this->totals->for($in->locationId, $in->businessDate);

        return (object) [
            'business_date' => $in->businessDate,
            'location_today' => now($tz)->toDateString(),
            'snapshot' => $snapshot,
            'open_shifts' => $openShifts->map(fn ($s) => (array) $s)->all(),
            'open_orders_count' => $openOrders,
            'unapproved_variance_count' => $unapprovedVariance,
            'closable' => $openShifts->isEmpty() && $openOrders === 0,
            'record' => $record,
        ];
    }
}
