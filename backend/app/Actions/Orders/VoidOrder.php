<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Quantity;
use App\Domain\Orders\OpenOrderLock;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\OrderHasPayments;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Voids the whole order — a walkout, a mis-rung tab, a till started by mistake. Every
 * non-voided line restocks (an already-voided line already restocked itself; doing it
 * again would double the shelf). Refuses outright once money has actually changed
 * hands: the payments are their own append-only record and must be voided first, or
 * the till and the ledger would quietly disagree about what was taken.
 */
final class VoidOrder
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly StockLedger $stock,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(VoidOrderInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            if ($order->paid_cents > 0) {
                throw new OrderHasPayments($order->id, $order->paid_cents);
            }

            // Restock asks about the present, not the snapshot: a variant retired since
            // the sale simply isn't restocked. A line already voided individually was
            // already restocked at that time — restocking it again here would double it.
            foreach ($order->lines()->whereNull('voided_at')->get() as $line) {
                $variant = ProductVariant::withTrashed()->find($line->variant_id);
                if ($variant !== null && $variant->track_inventory) {
                    $this->stock->restock($variant->id, $order->location_id, Quantity::fromString($line->qty), 'order_line', $line->id, $in->actorId);
                }
            }

            $order->forceFill([
                'status' => OrderStatus::Voided,
                'voided_at' => now(),
                'void_reason' => $in->reason,
                'version' => $order->version + 1,
            ])->save();

            $this->audit->record('order.void', $order, $in->actorId, [
                'order_id' => $order->id, 'reason' => $in->reason,
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
