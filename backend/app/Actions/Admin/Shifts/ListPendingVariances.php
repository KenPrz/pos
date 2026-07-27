<?php

declare(strict_types=1);

namespace App\Actions\Admin\Shifts;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closed shifts whose drawer variance is over threshold and not yet signed off.
 *
 * The three conditions are lifted from ApproveVariance's own guards so there is exactly one
 * definition of "needs approval" in the system: an open shift has no count yet, an
 * at-or-under-threshold variance is refused with `under_threshold`, and an approved one is
 * refused with `variance_already_approved`. Listing any of them would offer an approval the
 * API rejects.
 *
 * Read-only: no transaction, no audit entry.
 */
final class ListPendingVariances
{
    /** @return Collection<int, object> */
    public function execute(ListPendingVariancesInput $in): Collection
    {
        // Unlike GetBusinessDay, this spans locations, each of which may override the
        // threshold — so it is resolved per row in SQL rather than once in PHP, with the
        // config default bound as the coalesce fallback.
        $default = (int) config('pos.shifts.variance_approval_threshold_cents');

        $query = DB::table('shifts as s')
            ->join('registers as r', 'r.id', '=', 's.register_id')
            ->join('locations as l', 'l.id', '=', 'r.location_id')
            ->join('users as u', 'u.id', '=', 's.opened_by')
            ->whereNotNull('s.closed_at')
            ->whereNull('s.variance_approved_at')
            ->whereRaw('abs(s.variance_cents) > coalesce(l.variance_approval_threshold_cents, ?)', [$default]);

        // null = admin, every location. An empty list is a holder with no locations, which
        // must return nothing rather than everything — `whereIn` with [] does that.
        if ($in->locationIds !== null) {
            $query->whereIn('r.location_id', $in->locationIds);
        }

        return $query
            ->orderByDesc('s.closed_at')
            ->select([
                's.id as shift_id',
                'r.id as register_id', 'r.name as register_name',
                'l.id as location_id', 'l.name as location_name',
                'u.name as opened_by_name',
                's.opened_at', 's.closed_at',
                's.expected_cash_cents', 's.counted_cash_cents', 's.variance_cents',
            ])
            // Bound parameter rather than interpolating $default into a DB::raw column
            // expression — same value either way (an (int)-cast config default, never
            // user input), but this keeps every raw fragment's parameters uniformly bound.
            ->selectRaw('coalesce(l.variance_approval_threshold_cents, ?) as threshold_cents', [$default])
            ->get();
    }
}
