<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OpenOrderLock;
use App\Domain\Pricing\OrderTotals;
use App\Domain\Rbac\Permissions;
use App\Exceptions\Domain\DiscountNeedsSupervisor;
use App\Exceptions\Domain\DiscountScopeMismatch;
use App\Exceptions\Domain\LineAlreadyVoided;
use App\Models\Discount;
use App\Models\Order;
use App\Models\User;
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
        private readonly OpenOrderLock $lock,
        private readonly OrderTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(ApplyDiscountInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            $discount = Discount::where('is_active', true)->findOrFail($in->discountId);

            // The FormRequest only enforces the floor (order.line.add) — whether *this*
            // discount needs a supervisor depends on its own requires_supervisor flag,
            // which isn't known until the row is loaded. Mirrors SetLinePrepState's
            // in-action escalation; team context is already set by EnsureStaffSession.
            if ($discount->requires_supervisor) {
                $actor = User::query()->findOrFail($in->actorId);
                if (! $actor->can(Permissions::ORDER_DISCOUNT_APPLY)) {
                    throw new DiscountNeedsSupervisor($discount->id);
                }
            }

            if ($discount->scope === 'line') {
                // Must belong to this order — a stray or foreign line id is a 404, same
                // as any other cross-order lookup.
                $line = $order->lines()->whereKey($in->orderLineId)->firstOrFail();

                if ($line->voided_at !== null) {
                    throw new LineAlreadyVoided($line->id);
                }
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
