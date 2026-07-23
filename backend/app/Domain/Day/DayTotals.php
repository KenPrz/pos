<?php

declare(strict_types=1);

namespace App\Domain\Day;

use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * The snapshot for one location's local day, read from the ledgers — never a running
 * total. Sales/refunds/tax key on `orders.business_date` (ledger + order basis, same
 * source as SalesReport::byDay). Cash sums the shifts whose `closed_at`, in the location
 * timezone, lands on that date — the drawers counted at that day's close.
 *
 * ponytail: shifts carry no business_date column; a shift crossing midnight is attributed
 * to the day it closed. Add a shifts.business_date column only if exact overnight
 * attribution is ever needed.
 */
final class DayTotals
{
    public function for(string $locationId, string $businessDate): DaySnapshot
    {
        $tz = Location::query()->findOrFail($locationId)->timezone;

        $gross = (int) DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.status', 'captured')
            ->where('o.location_id', $locationId)
            ->where('o.business_date', $businessDate)
            ->sum('p.amount_cents');

        $refunds = (int) DB::table('refunds')
            ->where('location_id', $locationId)
            ->where('business_date', $businessDate)
            ->sum('amount_cents');

        $tax = (int) DB::table('orders')
            ->where('location_id', $locationId)
            ->where('status', 'closed')
            ->where('business_date', $businessDate)
            ->sum('tax_cents');

        // Drawers counted at this day's close: shifts at the location closed on this local
        // date. Bindings order: [tz, date, locationId].
        $cash = DB::table('shifts as s')
            ->join('registers as r', 'r.id', '=', 's.register_id')
            ->whereNotNull('s.closed_at')
            ->whereRaw('(s.closed_at at time zone ?)::date = ?', [$tz, $businessDate])
            ->where('r.location_id', $locationId)
            ->selectRaw('coalesce(sum(s.expected_cash_cents),0) as expected, coalesce(sum(s.counted_cash_cents),0) as counted, count(*) as n')
            ->first();

        $expected = (int) $cash->expected;
        $counted = (int) $cash->counted;

        return new DaySnapshot(
            grossSalesCents: $gross,
            refundsCents: $refunds,
            netSalesCents: $gross - $refunds,
            taxCents: $tax,
            expectedCashCents: $expected,
            countedCashCents: $counted,
            varianceCents: $counted - $expected,
            shiftCount: (int) $cash->n,
        );
    }
}
