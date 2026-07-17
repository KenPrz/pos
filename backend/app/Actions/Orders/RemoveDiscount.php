<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OpenOrderLock;
use App\Domain\Pricing\OrderTotals;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Removes a discount from an order. The `order_discounts` row is deleted outright — it
 * is register working state until the order closes, not the permanent record; the audit
 * log is the permanent trail (docs/02-data-model.md).
 */
final class RemoveDiscount
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly OrderTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(RemoveDiscountInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

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
