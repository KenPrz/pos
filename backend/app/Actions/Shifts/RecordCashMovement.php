<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\ShiftAlreadyClosed;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payout, paid-in, or drop — a signed movement of cash into or out of the drawer.
 * `kind` carries the sign (`amount_cents` is always positive) so a typo can't turn a
 * payout into a paid-in, and `reason` is mandatory: an unexplained drawer movement is
 * the classic internal-theft vector. See docs/02-data-model.md.
 *
 * Deliberately not an Eloquent model, mirroring AuditLogger — nothing reads these rows
 * at runtime except ShiftTotals' aggregate.
 */
final class RecordCashMovement
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(RecordCashMovementInput $in): object
    {
        return DB::transaction(function () use ($in): object {
            $shift = Shift::whereKey($in->shiftId)
                ->where('register_id', $in->registerId)   // another register's shift is a 404, not a 403 leak
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->closed_at !== null) {
                throw new ShiftAlreadyClosed($shift->id);
            }

            $row = [
                'id' => (string) Str::uuid7(),
                'shift_id' => $shift->id,
                'kind' => $in->kind,
                'amount_cents' => $in->amountCents,
                'reason' => $in->reason,
                'user_id' => $in->actorId,
                'created_at' => now(),
            ];

            DB::table('cash_movements')->insert($row);

            $this->audit->record('shift.cash_movement', $shift, $in->actorId, [
                'kind' => $in->kind,
                'amount_cents' => $in->amountCents,
                'reason' => $in->reason,
            ], registerId: $in->registerId);

            return (object) $row;
        });
    }
}
