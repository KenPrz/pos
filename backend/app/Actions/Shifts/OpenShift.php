<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\ShiftAlreadyOpen;
use App\Models\Shift;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts a drawer session. "One open shift per register" is the partial unique index's
 * job — we race straight into the insert and translate the violation, because an
 * application pre-check would just be a second, raceable copy of the invariant.
 */
final class OpenShift
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(OpenShiftInput $in): Shift
    {
        try {
            return DB::transaction(function () use ($in): Shift {
                $shift = Shift::create([
                    'register_id' => $in->registerId,
                    'opened_by' => $in->actorId,
                    'opened_at' => now(),
                    'opening_float_cents' => $in->openingFloatCents,
                ]);

                $this->audit->record('shift.open', $shift, $in->actorId, [
                    'opening_float_cents' => $in->openingFloatCents,
                ], registerId: $in->registerId);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ShiftAlreadyOpen($in->registerId);
        }
    }
}
