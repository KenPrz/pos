<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OpenOrderLock;
use App\Exceptions\Domain\OrderNotZero;
use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * Closes a zero-total order without a tender — a 100% comp, or an abandoned empty
 * order that would otherwise block shift close forever (payments must be > 0, so no
 * tender can ever reach it).
 *
 * This is NOT a manual close: it is the same definition — the order closes when
 * captured payments reach the total — evaluated at zero, where the captured sum (0)
 * already equals the total (0). Anything nonzero is refused.
 */
final class SettleZeroOrder
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(SettleZeroOrderInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            if ($order->total_cents !== 0 || $order->paid_cents !== 0) {
                throw new OrderNotZero($order->id, $order->total_cents - $order->paid_cents);
            }

            $order->forceFill([
                'status' => OrderStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $in->actorId,
                'version' => $order->version + 1,
            ])->save();

            $this->audit->record('order.settle_zero', $order, $in->actorId, [
                'number' => $order->number,
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
