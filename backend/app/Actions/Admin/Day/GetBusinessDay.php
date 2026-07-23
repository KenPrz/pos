<?php
// backend/app/Actions/Admin/Day/GetBusinessDay.php
declare(strict_types=1);

namespace App\Actions\Admin\Day;

use App\Domain\Day\DayTotals;
use App\Models\BusinessDay;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Everything the End-Of-Day screen needs for one location-date: live totals, the close
 * blockers (open shifts / open orders), a non-blocking unapproved-variance count, whether
 * the day is closable, and the close record if one exists. Read-only — computes totals
 * live even for an already-closed day; the persisted snapshot lives on the record itself.
 */
final class GetBusinessDay
{
    public function __construct(private readonly DayTotals $totals) {}

    public function execute(GetBusinessDayInput $in): object
    {
        $tz = Location::query()->findOrFail($in->locationId)->timezone;

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

        // Shifts closed on this local date whose variance is over threshold but unsigned.
        // Non-blocking — surfaced as a warning only.
        $unapprovedVariance = (int) DB::table('shifts as s')
            ->join('registers as r', 'r.id', '=', 's.register_id')
            ->whereNotNull('s.closed_at')
            ->whereNull('s.variance_approved_at')
            ->whereRaw('(s.closed_at at time zone ?)::date = ?', [$tz, $in->businessDate])
            ->where('r.location_id', $in->locationId)
            ->where('s.variance_cents', '<>', 0)
            ->count();

        $record = BusinessDay::query()
            ->where('location_id', $in->locationId)
            ->where('business_date', $in->businessDate)
            ->whereNull('reopened_at')
            ->first();

        return (object) [
            'business_date' => $in->businessDate,
            'snapshot' => $this->totals->for($in->locationId, $in->businessDate),
            'open_shifts' => $openShifts->map(fn ($s) => (array) $s)->all(),
            'open_orders_count' => $openOrders,
            'unapproved_variance_count' => $unapprovedVariance,
            'closable' => $openShifts->isEmpty() && $openOrders === 0,
            'record' => $record,
        ];
    }
}
