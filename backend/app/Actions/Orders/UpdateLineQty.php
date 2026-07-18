<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Money;
use App\Domain\Money\Quantity;
use App\Domain\Orders\OpenOrderLock;
use App\Domain\Pricing\OrderTotals;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\FiredLineRequiresSupervisor;
use App\Exceptions\Domain\LineAlreadyVoided;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Sets a line's absolute quantity. The stock ledger sees only the delta; the money
 * columns rescale from the line's own frozen snapshots — the live catalog is never
 * consulted after add (docs/02-data-model.md). Shrinking a fired line is the same
 * fraud surface as voiding a sent line, and takes the same permission.
 */
final class UpdateLineQty
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly StockLedger $stock,
        private readonly OrderTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(UpdateLineQtyInput $in): OrderLine
    {
        return DB::transaction(function () use ($in): OrderLine {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            /** @var OrderLine $line */
            $line = $order->lines()->whereKey($in->lineId)->firstOrFail();
            if ($line->voided_at !== null) {
                throw new LineAlreadyVoided($line->id);
            }

            $old = Quantity::fromString($line->qty);
            $new = Quantity::fromString($in->qty);
            $delta = $new->minus($old);

            if ($delta->isNegative() && in_array($line->prep_state, ['in_progress', 'ready'], true) && ! $in->actorMayVoidLines) {
                throw new FiredLineRequiresSupervisor($line->id);
            }

            if (! $delta->isZero()) {
                $variant = ProductVariant::withTrashed()->find($line->variant_id);
                if ($variant !== null && $variant->track_inventory) {
                    $delta->isPositive()
                        ? $this->stock->sell($variant->id, $order->location_id, $delta, 'order_line', $line->id, $in->actorId)
                        : $this->stock->restock($variant->id, $order->location_id, $delta->negated(), 'order_line', $line->id, $in->actorId);
                }
            }

            $perUnitDelta = Money::fromCents((int) $line->modifiers()->sum('price_delta_cents'));

            $line->forceFill([
                'qty' => (string) $new,
                'modifiers_total_cents' => $perUnitDelta->multipliedByQuantity($new)->cents,
            ])->save();

            $this->totals->recalculate($order);
            $order->forceFill(['version' => $order->version + 1])->save();

            $this->audit->record('order.line.qty', $line, $in->actorId, [
                'order_id' => $order->id, 'from' => (string) $old, 'to' => (string) $new,
            ], registerId: $in->registerId);

            return $line->refresh()->setRelation('order', $order->fresh(['lines', 'discounts']));
        });
    }
}
