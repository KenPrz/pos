<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Money;
use App\Domain\Money\Quantity;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\NoOpenShift;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\RefundAmountZero;
use App\Exceptions\Domain\RefundExceedsOriginal;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\RefundLine;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A refund is new rows, never a mutation — the original order stays closed forever and
 * is never written to. The amount is derived from the line's frozen price/tax snapshot,
 * never accepted from the client: a cashier can choose *how much of the line* comes
 * back (qty) and whether it goes back on the shelf (restock), nothing else.
 * See docs/02-data-model.md (refunds).
 */
final class RefundOrder
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(RefundOrderInput $in): Refund
    {
        return DB::transaction(function () use ($in): Refund {
            $register = Register::with('location')->findOrFail($in->registerId);

            // Another location's order is a 404, not a bypass — teams scope permission
            // checks, but record fetches must still be location-scoped by hand (docs/05-rbac.md).
            // Locked here: this is what serializes two concurrent refunds of the same order.
            $order = Order::whereKey($in->originalOrderId)
                ->where('location_id', $register->location_id)
                ->lockForUpdate()->firstOrFail();

            // You refund what was sold, not an open tab.
            if ($order->status !== OrderStatus::Closed) {
                throw new OrderClosed($order->id, $order->status->value);
            }

            $shift = Shift::openFor($in->registerId) ?? throw new NoOpenShift($in->registerId);

            // Pass 1: validate every line and derive its amount, without writing
            // anything yet — refunds.amount_cents > 0 is a DB check constraint, so the
            // parent row can only be inserted once the true total is known.
            $planned = [];
            $seenQty = [];      // original_order_line_id => Quantity already accounted for
            $seenAmount = [];   // original_order_line_id => Money already refunded

            foreach ($in->lines as $lineInput) {
                $orderLine = $order->lines()
                    ->whereNull('voided_at')
                    ->whereKey($lineInput->originalOrderLineId)
                    ->firstOrFail();

                $qty = Quantity::fromString($lineInput->qty);
                $origQty = Quantity::fromString($orderLine->qty);

                // line_total_cents is the gross, tax-inclusive amount at a tax-inclusive
                // location (tax_cents is only the portion of it already embedded) but the
                // tax-exclusive net amount everywhere else (tax_cents is added on top at
                // the till) — see OrderTotals. Adding tax_cents unconditionally would
                // refund the embedded VAT a second time.
                $lineTotal = $order->prices_include_tax
                    ? Money::fromCents($orderLine->line_total_cents)
                    : Money::fromCents($orderLine->line_total_cents + $orderLine->tax_cents);

                if (! isset($seenQty[$orderLine->id])) {
                    // Sum of prior refund_lines for this original line, read inside THIS
                    // transaction — after the order lock above, so no concurrent refund
                    // of the same order can slip a second request under the limit.
                    $seenQty[$orderLine->id] = Quantity::fromString(
                        (string) DB::table('refund_lines')
                            ->where('original_order_line_id', $orderLine->id)
                            ->sum('qty')
                    );
                    $seenAmount[$orderLine->id] = Money::fromCents(
                        (int) DB::table('refund_lines')
                            ->where('original_order_line_id', $orderLine->id)
                            ->sum('amount_cents')
                    );
                }

                $alreadyRefunded = $seenQty[$orderLine->id];
                $cumulative = $alreadyRefunded->plus($qty);

                if ($cumulative->greaterThan($origQty)) {
                    throw new RefundExceedsOriginal(
                        $orderLine->id,
                        (string) $origQty,
                        (string) $alreadyRefunded,
                        (string) $qty,
                    );
                }

                $seenQty[$orderLine->id] = $cumulative;

                $alreadyRefundedAmount = $seenAmount[$orderLine->id];
                $remaining = $lineTotal->minus($alreadyRefundedAmount);

                // The single rounding site: a line's refund is the same fraction of its
                // frozen (price + tax) snapshot that this qty is of the line's own qty —
                // capped at what's actually left on the line, and exact (not fractioned)
                // on the qty that exhausts it. Deriving each refund from qty alone (with
                // no amount cap) can invent or lose a penny across a piecewise refund
                // when the line's total doesn't divide evenly by its qty; capping at the
                // remainder, and taking the remainder exactly on exhaustion, keeps the
                // pieces summing to precisely the line's total every time.
                $lineRefund = $cumulative->equals($origQty)
                    ? $remaining
                    : $lineTotal->fraction($qty->milli, $origQty->milli)->min($remaining);

                // A zero-money refund (fully discounted line, or a fraction rounding to
                // nothing) would violate the schema's amount > 0 checks as a raw 500.
                if (! $lineRefund->isPositive()) {
                    throw new RefundAmountZero($orderLine->id);
                }

                $seenAmount[$orderLine->id] = $alreadyRefundedAmount->plus($lineRefund);

                $planned[] = [
                    'orderLine' => $orderLine,
                    'qty' => $qty,
                    'qtyString' => $lineInput->qty,
                    'amount' => $lineRefund,
                    'restock' => $lineInput->restock,
                ];
            }

            $total = Money::sum(array_map(static fn (array $p): Money => $p['amount'], $planned));

            $refund = Refund::create([
                'original_order_id' => $order->id,
                'location_id' => $register->location_id,
                'register_id' => $register->id,
                'shift_id' => $shift->id,
                // The local calendar day at the store issuing the refund.
                'business_date' => now($register->location->timezone)->toDateString(),
                'driver' => $in->driver,
                'amount_cents' => $total->cents,
                'reason' => $in->reason,
                'user_id' => $in->actorId,
                'created_at' => now(),
            ]);

            $createdLines = [];

            foreach ($planned as $p) {
                $refundLine = RefundLine::create([
                    'refund_id' => $refund->id,
                    'original_order_line_id' => $p['orderLine']->id,
                    'qty' => $p['qtyString'],
                    'amount_cents' => $p['amount']->cents,
                    'restock' => $p['restock'],
                ]);
                $createdLines[] = $refundLine;

                if ($p['restock']) {
                    // Restock asks about the present, not the snapshot: a variant retired
                    // since the sale simply isn't restocked (VoidLine's rule).
                    $variant = ProductVariant::withTrashed()->find($p['orderLine']->variant_id);
                    if ($variant !== null && $variant->track_inventory) {
                        $this->stock->restock(
                            $variant->id, $order->location_id, $p['qty'],
                            'refund_line', $refundLine->id, $in->actorId,
                        );
                    }
                }
            }

            $this->audit->record('refund.create', $refund, $in->actorId, [
                'original_order_id' => $order->id,
                'amount_cents' => $total->cents,
            ], registerId: $in->registerId);

            // In-memory, like every sibling action returns its just-created rows: a
            // fresh SELECT would hand back Postgres's normalized "2.000" instead of the
            // qty the client actually sent.
            return $refund->setRelation('lines', new Collection($createdLines));
        });
    }
}
