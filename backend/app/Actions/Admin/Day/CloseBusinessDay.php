<?php
// backend/app/Actions/Admin/Day/CloseBusinessDay.php
declare(strict_types=1);

namespace App\Actions\Admin\Day;

use App\Domain\Audit\AuditLogger;
use App\Domain\Day\DayTotals;
use App\Exceptions\Domain\DayHasOpenOrders;
use App\Exceptions\Domain\DayHasOpenShifts;
use App\Models\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * Freezes one location's local day: reconcile every drawer, record the deposit + a fixed
 * checklist, snapshot the totals from the ledgers. Preconditions — every shift closed,
 * zero open orders — are the reconciliation contract; unapproved variances DON'T block
 * (same philosophy as variance itself). Re-closing a reopened day updates the one row.
 * See the End-Of-Day design and docs/02-data-model.md.
 */
final class CloseBusinessDay
{
    public function __construct(
        private readonly DayTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CloseBusinessDayInput $in): BusinessDay
    {
        return DB::transaction(function () use ($in): BusinessDay {
            $openShifts = DB::table('shifts as s')
                ->join('registers as r', 'r.id', '=', 's.register_id')
                ->whereNull('s.closed_at')
                ->where('r.location_id', $in->locationId)
                ->get(['r.id as register_id', 'r.name as register_name', 's.id as shift_id']);
            if ($openShifts->isNotEmpty()) {
                throw new DayHasOpenShifts($in->locationId, $openShifts->map(fn ($s) => (array) $s)->all());
            }

            $openOrders = DB::table('orders')
                ->where('location_id', $in->locationId)
                ->where('business_date', $in->businessDate)
                ->where('status', 'open')
                ->get(['id', 'number']);
            if ($openOrders->isNotEmpty()) {
                throw new DayHasOpenOrders($in->locationId, $openOrders->map(fn ($o) => (array) $o)->all());
            }

            $snap = $this->totals->for($in->locationId, $in->businessDate);

            $row = BusinessDay::query()->updateOrCreate(
                ['location_id' => $in->locationId, 'business_date' => $in->businessDate],
                [
                    'closed_by' => $in->actorId,
                    'closed_at' => now(),
                    'gross_sales_cents' => $snap->grossSalesCents,
                    'refunds_cents' => $snap->refundsCents,
                    'net_sales_cents' => $snap->netSalesCents,
                    'tax_cents' => $snap->taxCents,
                    'expected_cash_cents' => $snap->expectedCashCents,
                    'counted_cash_cents' => $snap->countedCashCents,
                    'variance_cents' => $snap->varianceCents,
                    'shift_count' => $snap->shiftCount,
                    'deposit_cents' => $in->depositCents,
                    'checklist' => $in->checklist,
                    'note' => $in->note,
                    'reopened_at' => null,
                    'reopened_by' => null,
                ],
            );

            $this->audit->record('day.close', $row, $in->actorId, [
                'business_date' => $in->businessDate,
                'net_sales_cents' => $snap->netSalesCents,
                'variance_cents' => $snap->varianceCents,
                'deposit_cents' => $in->depositCents,
                'shift_count' => $snap->shiftCount,
            ]);

            return $row;
        });
    }
}
