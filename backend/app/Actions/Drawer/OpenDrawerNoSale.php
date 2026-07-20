<?php

declare(strict_types=1);

namespace App\Actions\Drawer;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\NoOpenShift;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Opening the drawer with no sale attached. Moves no money, so unlike a cash movement
 * there is nothing to aggregate and no table — the audit row IS the record.
 *
 * `reason` is mandatory for the same argument RecordCashMovement makes: an unexplained
 * drawer opening is the classic internal-theft vector. See docs/05-rbac.md.
 */
final class OpenDrawerNoSale
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(OpenDrawerNoSaleInput $in): object
    {
        return DB::transaction(function () use ($in): object {
            // A drawer opening belongs to a shift: the shift is the accountability unit,
            // so an opening with no open shift has nothing to attribute it to.
            $shift = Shift::openFor($in->registerId) ?? throw new NoOpenShift($in->registerId);

            $this->audit->record('drawer.no_sale', $shift, $in->actorId, [
                'reason' => $in->reason,
            ], registerId: $in->registerId);

            return (object) ['authorized' => true, 'shift_id' => $shift->id];
        });
    }
}
