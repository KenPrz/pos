<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\OrderClosed;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * Reopens a closed order — food service, when the customer orders another round after
 * settling up. Only meaningful for a *closed* order: reopening one that's still open is
 * a no-op request, and a voided order is a corpse — both throw the same `OrderClosed`
 * a stale write would, and the message reads fine either way.
 *
 * Payments and stock are untouched: the goods already left with the customer and the
 * money already changed hands. Only the lifecycle state moves. No If-Match — the client
 * is acting on a closed order it just looked up, and the row lock serializes the rest.
 */
final class ReopenOrder
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(ReopenOrderInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $locationId = Register::findOrFail($in->registerId)->location_id;

            $order = Order::whereKey($in->orderId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== OrderStatus::Closed) {
                throw new OrderClosed($order->id, $order->status->value);
            }

            $order->forceFill([
                'status' => OrderStatus::Open,
                'closed_at' => null,
                'closed_by' => null,
                'version' => $order->version + 1,
            ])->save();

            $this->audit->record('order.reopen', $order, $in->actorId, [
                'order_id' => $order->id, 'reason' => $in->reason,
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
