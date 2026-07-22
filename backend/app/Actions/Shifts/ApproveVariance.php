<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\VarianceAlreadyApproved;
use App\Exceptions\Domain\VarianceApprovalNotRequired;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * A supervisor signs off an over-threshold drawer variance. The shift is already
 * closed — approval is an audit event, never a gate on closing (docs/03-api.md:
 * blocking the close is how you end up with terminals unplugged mid-count).
 */
final class ApproveVariance
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ApproveVarianceInput $in): Shift
    {
        return DB::transaction(function () use ($in): Shift {
            $location = Register::findOrFail($in->registerId)->location;

            $shift = Shift::whereKey($in->shiftId)
                ->whereHas('register', fn ($q) => $q->where('location_id', $location->id))
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->closed_at === null) {
                throw new VarianceApprovalNotRequired($shift->id, 'shift_open');
            }
            $threshold = (int) ($location->variance_approval_threshold_cents ?? config('pos.shifts.variance_approval_threshold_cents'));
            if (abs((int) $shift->variance_cents) <= $threshold) {
                throw new VarianceApprovalNotRequired($shift->id, 'under_threshold');
            }
            if ($shift->variance_approved_at !== null) {
                throw new VarianceAlreadyApproved($shift->id, $shift->variance_approved_by, $shift->variance_approved_at->toIso8601String());
            }

            $shift->forceFill([
                'variance_approved_by' => $in->actorId,
                'variance_approved_at' => now(),
            ])->save();

            $this->audit->record('shift.approve_variance', $shift, $in->actorId, [
                'variance_cents' => $shift->variance_cents,
            ], registerId: $in->registerId);

            return $shift->refresh();
        });
    }
}
