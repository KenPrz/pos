<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OpenOrderLock;
use App\Exceptions\Domain\TransferSameShift;
use App\Exceptions\Domain\TransferTargetNoShift;
use App\Models\Order;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Hands a tab to another drawer — the push model the industry uses (spec), expressed
 * in our accountability unit: the receiving register's open shift. A tab cannot
 * outlive the drawer accountable for it (docs/03-api.md), so shift close says
 * "transfer these first" and this is the verb it means. opened_by is history and
 * never changes; payments carry their own shift_id, so money already taken stays
 * attributed to the drawer that physically took it.
 */
final class TransferOrder
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(TransferOrderInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            $target = Register::where('id', $in->targetRegisterId)
                ->where('location_id', $order->location_id)
                ->where('is_active', true)
                ->firstOrFail();

            $targetShift = Shift::openFor($target->id) ?? throw new TransferTargetNoShift($target->id);
            if ($targetShift->id === $order->shift_id) {
                throw new TransferSameShift($targetShift->id);
            }

            $from = ['register_id' => $order->register_id, 'shift_id' => $order->shift_id];
            $order->forceFill([
                'register_id' => $target->id,
                'shift_id' => $targetShift->id,
                'version' => $order->version + 1,
            ])->save();

            $this->audit->record('order.transfer', $order, $in->actorId, [
                'from' => $from,
                'to' => ['register_id' => $target->id, 'shift_id' => $targetShift->id],
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
