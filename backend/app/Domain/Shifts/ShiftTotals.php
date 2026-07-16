<?php

declare(strict_types=1);

namespace App\Domain\Shifts;

use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Drawer math, shared by GetCurrentShift and CloseShift. Variance is computed at
 * close from the ledger tables, never kept as a running total.
 * See docs/02-data-model.md (cash accountability).
 */
final class ShiftTotals
{
    public function expectedCashCents(Shift $shift): int
    {
        $cashSales = (int) DB::table('payments')
            ->where('shift_id', $shift->id)
            ->where('driver', 'cash')
            ->where('status', 'captured')
            ->sum('amount_cents');

        // Zero rows until M4 ships refunds and cash movements; queried now so the
        // formula is already right the day they exist.
        $cashRefunds = (int) DB::table('refunds')
            ->where('shift_id', $shift->id)
            ->where('driver', 'cash')
            ->sum('amount_cents');

        $movements = (int) DB::table('cash_movements')
            ->where('shift_id', $shift->id)
            ->selectRaw("coalesce(sum(case when kind = 'paid_in' then amount_cents else -amount_cents end), 0) as net")
            ->value('net');

        return $shift->opening_float_cents + $cashSales - $cashRefunds + $movements;
    }

    /** @return array{orders_closed: int, total_cents: int, cash_cents: int} */
    public function salesSummary(Shift $shift): array
    {
        $byDriver = DB::table('payments')
            ->where('shift_id', $shift->id)
            ->where('status', 'captured')
            ->groupBy('driver')
            ->selectRaw('driver, sum(amount_cents) as cents')
            ->pluck('cents', 'driver');

        return [
            'orders_closed' => DB::table('orders')->where('shift_id', $shift->id)->where('status', 'closed')->count(),
            'total_cents' => (int) $byDriver->sum(),
            'cash_cents' => (int) ($byDriver['cash'] ?? 0),
        ];
    }
}
