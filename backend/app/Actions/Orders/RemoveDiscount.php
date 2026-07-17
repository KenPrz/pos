<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\OrderTotals;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * Removes a discount from an order. The `order_discounts` row is deleted outright — it
 * is register working state until the order closes, not the permanent record; the audit
 * log is the permanent trail (docs/02-data-model.md).
 */
final class RemoveDiscount
{
    public function __construct(
        private readonly OrderTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(RemoveDiscountInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $locationId = Register::findOrFail($in->registerId)->location_id;

            $order = Order::whereKey($in->orderId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== OrderStatus::Open) {
                throw new OrderClosed($order->id, $order->status->value);
            }
            if ($order->version !== $in->expectedVersion) {
                throw new OrderVersionConflict($order->id, $in->expectedVersion, $order->version);
            }

            $orderDiscount = $order->discounts()->whereKey($in->orderDiscountId)->firstOrFail();
            $discountId = $orderDiscount->discount_id;
            $orderDiscount->delete();

            $this->totals->recalculate($order);
            $order->forceFill(['version' => $order->version + 1])->save();

            $this->audit->record('order.discount.remove', $orderDiscount, $in->actorId, [
                'order_id' => $order->id, 'discount_id' => $discountId,
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
