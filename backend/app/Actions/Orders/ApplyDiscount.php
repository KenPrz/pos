<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\OrderTotals;
use App\Exceptions\Domain\DiscountScopeMismatch;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * Attaches a reusable discount to an order (or one of its lines). Only the scaffolding
 * lands here — `amount_cents` is written as 0 and OrderTotals::recalculate (via
 * DiscountResolver) is what computes the real figure, in the same transaction.
 *
 * Scope is data on the discount row, not the request: a `line` discount cannot be told
 * apart from an `order` one until the row is loaded, so the mismatch check happens here,
 * inside the lock, rather than in the FormRequest (docs/04-backend-conventions.md).
 */
final class ApplyDiscount
{
    public function __construct(
        private readonly OrderTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(ApplyDiscountInput $in): Order
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

            $discount = Discount::where('is_active', true)->findOrFail($in->discountId);

            if ($discount->scope === 'line') {
                // Must belong to this order — a stray or foreign line id is a 404, same
                // as any other cross-order lookup.
                $order->lines()->whereKey($in->orderLineId)->firstOrFail();
            } elseif ($in->orderLineId !== null) {
                throw new DiscountScopeMismatch($discount->id, $discount->scope);
            }

            $orderDiscount = $order->discounts()->create([
                'order_line_id' => $in->orderLineId,
                'discount_id' => $discount->id,
                'name_snapshot' => $discount->name,
                'amount_cents' => 0,   // DiscountResolver (via OrderTotals) writes the real figure below
                'applied_by' => $in->actorId,
                'reason' => $in->reason,
                'created_at' => now(),
            ]);

            $this->totals->recalculate($order);
            $order->forceFill(['version' => $order->version + 1])->save();

            $this->audit->record('order.discount.apply', $orderDiscount, $in->actorId, [
                'order_id' => $order->id, 'discount_id' => $discount->id, 'reason' => $in->reason,
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
