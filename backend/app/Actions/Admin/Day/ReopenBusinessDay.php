<?php
// backend/app/Actions/Admin/Day/ReopenBusinessDay.php
declare(strict_types=1);

namespace App\Actions\Admin\Day;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\DayNotClosed;
use App\Models\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * Un-freezes a closed day — the ONLY thing that lets a shift open on a closed date again.
 * Exceptional and admin-only (the route gates on is_admin); the reason is mandatory and
 * lands in the audit log. Sets reopened_at/by on the one row; a later CloseBusinessDay
 * re-snapshots and clears them.
 */
final class ReopenBusinessDay
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(ReopenBusinessDayInput $in): BusinessDay
    {
        return DB::transaction(function () use ($in): BusinessDay {
            $row = BusinessDay::query()
                ->where('location_id', $in->locationId)
                ->where('business_date', $in->businessDate)
                ->whereNull('reopened_at')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new DayNotClosed($in->locationId, $in->businessDate);
            }

            $row->forceFill(['reopened_at' => now(), 'reopened_by' => $in->actorId])->save();

            $this->audit->record('day.reopen', $row, $in->actorId, [
                'business_date' => $in->businessDate,
                'reason' => $in->reason,
            ]);

            return $row;
        });
    }
}
