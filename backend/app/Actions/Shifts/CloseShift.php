<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Rbac\Permissions;
use App\Domain\Shifts\ShiftTotals;
use App\Exceptions\Domain\NotShiftOwner;
use App\Exceptions\Domain\ShiftAlreadyClosed;
use App\Exceptions\Domain\ShiftHasOpenOrders;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Counts the drawer. Variance is recorded, never blocked — a drawer that refuses to
 * close gets closed by unplugging the terminal, and then there's no data at all.
 * Approval beyond the threshold is an audit event (M4), not a gate.
 */
final class CloseShift
{
    public function __construct(
        private readonly ShiftTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CloseShiftInput $in): Shift
    {
        return DB::transaction(function () use ($in): Shift {
            $shift = Shift::whereKey($in->shiftId)
                ->where('register_id', $in->registerId)   // another register's shift is a 404, not a 403 leak
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->closed_at !== null) {
                throw new ShiftAlreadyClosed($shift->id);
            }

            // docs/05-rbac.md: own shift, or a supervisor closing someone else's. This is
            // a Policy question ("own") that shift.close as a flat permission can't express.
            if ($shift->opened_by !== $in->actorId) {
                $actor = User::findOrFail($in->actorId);
                if (! $actor->can(Permissions::SHIFT_APPROVE_VARIANCE)) {
                    throw new NotShiftOwner($shift->id, $shift->opened_by);
                }
            }

            $open = $shift->orders()->where('status', 'open')->get(['id', 'number']);
            if ($open->isNotEmpty()) {
                // A tab cannot outlive the drawer that's accountable for it.
                throw new ShiftHasOpenOrders($shift->id, $open->map->only(['id', 'number'])->all());
            }

            $expected = $this->totals->expectedCashCents($shift);

            $shift->forceFill([
                'closed_by' => $in->actorId,
                'closed_at' => now(),
                'counted_cash_cents' => $in->countedCashCents,
                'expected_cash_cents' => $expected,
                'variance_cents' => $in->countedCashCents - $expected,
                'close_note' => $in->note,
            ])->save();

            // Staff sessions end at shift close (docs/01-architecture.md). Matches the
            // ability string StaffLogin issues; device tokens don't carry it.
            PersonalAccessToken::query()
                ->where('abilities', 'like', '%register:'.$shift->register_id.'%')
                ->delete();

            $this->audit->record('shift.close', $shift, $in->actorId, [
                'counted_cash_cents' => $in->countedCashCents,
                'expected_cash_cents' => $expected,
                'variance_cents' => $shift->variance_cents,
            ], registerId: $in->registerId);

            return $shift;
        });
    }
}
