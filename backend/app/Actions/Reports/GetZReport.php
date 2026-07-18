<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Domain\Shifts\ShiftTotals;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * The Z-report: what a supervisor reads to close out a drawer, and what a cashier reads
 * to see their own. Scoped to the acting register's LOCATION, not its own register — a
 * supervisor checks any drawer at their store, not just the one they're standing at.
 * See docs/02-data-model.md (cash accountability) and docs/05-rbac.md.
 *
 * Read-only: no transaction, no audit entry. Works for an open shift exactly as it does
 * for a closed one — an open shift's Z is simply the running X-report.
 */
final class GetZReport
{
    public function __construct(private readonly ShiftTotals $totals) {}

    public function execute(string $shiftId, string $registerId): ZReport
    {
        $locationId = Register::findOrFail($registerId)->location_id;

        // Location-scoped through the shift's own register, not the acting register's
        // id — that's the whole point of a Z-report a supervisor can pull for any
        // drawer at the store. Another location's shift is a 404, not a bypass.
        $shift = Shift::whereKey($shiftId)
            ->whereHas('register', fn ($query) => $query->where('location_id', $locationId))
            ->firstOrFail();

        $salesByDriver = DB::table('payments')
            ->where('shift_id', $shift->id)
            ->where('status', 'captured')
            ->groupBy('driver')
            ->selectRaw('driver, sum(amount_cents) as cents')
            ->pluck('cents', 'driver')
            ->map(static fn (mixed $cents): int => (int) $cents)
            ->all();

        $refundsByDriver = DB::table('refunds')
            ->where('shift_id', $shift->id)
            ->groupBy('driver')
            ->selectRaw('driver, sum(amount_cents) as cents')
            ->pluck('cents', 'driver')
            ->map(static fn (mixed $cents): int => (int) $cents)
            ->all();

        $movementsByKind = DB::table('cash_movements')
            ->where('shift_id', $shift->id)
            ->groupBy('kind')
            ->selectRaw('kind, sum(amount_cents) as cents')
            ->pluck('cents', 'kind');

        return new ZReport(
            shift: $shift,
            salesByDriver: $salesByDriver,
            refundsByDriver: $refundsByDriver,
            movements: [
                'paid_in' => (int) ($movementsByKind['paid_in'] ?? 0),
                'payout' => (int) ($movementsByKind['payout'] ?? 0),
                'drop' => (int) ($movementsByKind['drop'] ?? 0),
            ],
            ordersClosed: DB::table('orders')->where('shift_id', $shift->id)->where('status', 'closed')->count(),
            // A split's original is voided with void_reason "split into ..." (SplitOrder)
            // — that is bookkeeping, not a genuine void, so it is counted separately.
            ordersVoided: DB::table('orders')->where('shift_id', $shift->id)->where('status', 'voided')
                ->where('void_reason', 'not like', 'split into%')->count(),
            ordersSplit: DB::table('orders')->where('shift_id', $shift->id)->where('status', 'voided')
                ->where('void_reason', 'like', 'split into%')->count(),
            expectedCashCents: $this->totals->expectedCashCents($shift),
        );
    }
}
