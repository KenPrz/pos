<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Quantity;
use App\Domain\Orders\OpenOrderLock;
use App\Domain\Pricing\OrderTotals;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\LineAlreadyVoided;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Lines are voided, never deleted — "what did the cashier remove, and when" is a
 * fraud question. Tracked stock goes back on the shelf in the same transaction.
 */
final class VoidLine
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly StockLedger $stock,
        private readonly OrderTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(VoidLineInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            $line = $order->lines()->whereKey($in->lineId)->firstOrFail();

            if ($line->voided_at !== null) {
                throw new LineAlreadyVoided($line->id);
            }

            $line->forceFill(['voided_at' => now(), 'voided_by' => $in->actorId])->save();

            // Restock asks about the present, not the snapshot: a variant retired since
            // the sale simply isn't restocked.
            $variant = ProductVariant::withTrashed()->find($line->variant_id);
            if ($variant !== null && $variant->track_inventory) {
                $this->stock->restock($variant->id, $order->location_id, Quantity::fromString($line->qty), 'order_line', $line->id, $in->actorId);
            }

            $this->totals->recalculate($order);
            $order->forceFill(['version' => $order->version + 1])->save();

            $this->audit->record('order.line.void', $line, $in->actorId, [
                'order_id' => $order->id, 'reason' => $in->reason, 'sku' => $line->sku_snapshot,
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
