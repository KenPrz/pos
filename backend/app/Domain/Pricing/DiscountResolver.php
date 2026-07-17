<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money\Discount as DiscountValueObject;
use App\Domain\Money\Money;
use App\Domain\Money\Quantity;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderLine;
use Illuminate\Support\Collection;

/**
 * Resolves `order_discounts` rows into cents against the order's *current* state, and
 * writes the result onto `order_lines.discount_cents`. Called by OrderTotals::recalculate,
 * inside the same transaction, before the per-line tax pass — so tax always lands on the
 * discounted base.
 *
 * There are exactly two places a discount cent is created: the M1 VO's amountFor() clamp
 * (never exceeds the base, never negative) and Money::allocateByRatios (deterministic
 * pennies, earliest absorbs). This class only orchestrates them — no arithmetic of its
 * own beyond addition.
 *
 * Percent rows re-resolve every call; an order gaining a line does not silently keep a
 * stale figure. amount_cents is therefore rewritten on every row, every time, even when
 * the number doesn't change.
 */
final class DiscountResolver
{
    public function resolve(Order $order): void
    {
        /** @var Collection<int, OrderLine> $lines */
        $lines = $order->lines()->whereNull('voided_at')->get();

        // Step 1: zero every non-voided line's discount_cents before re-resolving.
        // Voided lines are left alone — nothing here touches their bookkeeping.
        foreach ($lines as $line) {
            $line->discount_cents = 0;
        }

        /** @var array<string, Money> $bases Undiscounted base per line, keyed by line id. */
        $bases = [];
        foreach ($lines as $line) {
            $bases[$line->id] = $this->undiscountedBase($line);
        }

        $rows = OrderDiscount::query()
            ->where('order_id', $order->id)
            ->with('discount')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->resolveLineLevelRows($rows, $lines, $bases);
        $this->resolveOrderLevelRows($rows, $lines, $bases);

        foreach ($lines as $line) {
            $line->save();
        }
    }

    /**
     * Rows on the same line resolve sequentially against what's left of that line's base
     * after earlier line-level rows in this pass — mirroring the order-level rows below.
     * Each row is rewritten with the resolved figure, so stacked rows on one line always
     * sum to exactly the line's discount and can never jointly exceed its base.
     *
     * @param  Collection<int, OrderDiscount>  $rows
     * @param  Collection<int, OrderLine>  $lines
     * @param  array<string, Money>  $bases
     */
    private function resolveLineLevelRows(Collection $rows, Collection $lines, array $bases): void
    {
        /** @var array<string, Money> $remainingBases Each line's base net of the
         *  line-level rows already applied to it earlier in this pass. */
        $remainingBases = $bases;

        foreach ($rows->whereNotNull('order_line_id') as $row) {
            $line = $lines->firstWhere('id', $row->order_line_id);

            // Points at a line that is voided (or otherwise not in play): resolves to 0.
            if ($line === null) {
                $row->forceFill(['amount_cents' => 0])->save();

                continue;
            }

            $amount = $this->valueObjectFor($row)->amountFor($remainingBases[$line->id]);
            $row->forceFill(['amount_cents' => $amount->cents])->save();

            $line->discount_cents += $amount->cents;
            $remainingBases[$line->id] = $remainingBases[$line->id]->minus($amount);
        }
    }

    /**
     * Order-level rows resolve sequentially against the base remaining after earlier
     * order-level rows (and all line-level rows) have already been taken off — a second
     * 100% discount on an already-free order takes 0.
     *
     * @param  Collection<int, OrderDiscount>  $rows
     * @param  Collection<int, OrderLine>  $lines
     * @param  array<string, Money>  $bases
     */
    private function resolveOrderLevelRows(Collection $rows, Collection $lines, array $bases): void
    {
        /** @var array<string, Money> $netBases Each line's base net of its own line-level discount so far. */
        $netBases = [];
        foreach ($lines as $line) {
            $netBases[$line->id] = $bases[$line->id]->minus(Money::fromCents($line->discount_cents));
        }

        foreach ($rows->whereNull('order_line_id') as $row) {
            $remainingBase = Money::sum(array_values($netBases));

            $amount = $this->valueObjectFor($row)->amountFor($remainingBase);
            $row->forceFill(['amount_cents' => $amount->cents])->save();

            // Zero-base (or zero-amount) edge: nothing to allocate, and allocateByRatios
            // would reject ratios that sum to zero anyway.
            if ($amount->isZero() || $remainingBase->isZero()) {
                continue;
            }

            // Only lines with a positive remaining base get a ratio. A line already
            // driven to zero by an earlier line-level or order-level row has nothing
            // left to give — including it would still sum to the same ratios (a zero
            // ratio contributes nothing to allocateByRatios' math) but the remainder
            // walk inside allocateByRatios distributes stray pennies to *any* index,
            // including a zero-ratio one, which is exactly how a spent line ends up
            // with a share it can't afford.
            $lineIds = array_values(array_filter(
                array_keys($netBases),
                static fn (string $id): bool => $netBases[$id]->isPositive()
            ));

            $ratios = array_map(static fn (string $id): int => $netBases[$id]->cents, $lineIds);
            $shares = $amount->allocateByRatios($ratios);

            // Belt-and-braces: even restricted to positive-base lines, allocateByRatios'
            // remainder walk can still hand a line (near the end of the remainder cycle)
            // a share larger than its remaining base once earlier rows in *this* pass
            // have already eaten into it. Walk in line order, clamping each share to
            // what the line actually has left and carrying any overflow forward. The
            // total allocated never exceeds the total remaining base, so the carry
            // always finds a home by the last line.
            $carry = Money::zero();
            foreach ($lineIds as $index => $lineId) {
                $share = $shares[$index]->plus($carry);
                $applied = $share->min($netBases[$lineId]);
                $carry = $share->minus($applied);

                $line = $lines->firstWhere('id', $lineId);
                $line->discount_cents += $applied->cents;
                $netBases[$lineId] = $netBases[$lineId]->minus($applied);
            }
        }
    }

    /**
     * A row with no discount_id is ad-hoc (docs/02-data-model.md): there is no reusable
     * definition to re-resolve against. Ad-hoc discounts have no endpoint until M6 — fail
     * loudly rather than pinning the row at its stored amount, which would silently
     * ratchet it down every time the base shrinks and never back up.
     */
    private function valueObjectFor(OrderDiscount $row): DiscountValueObject
    {
        if ($row->discount_id === null) {
            throw new \LogicException('Ad-hoc discounts (null discount_id) are not implemented until M6.');
        }

        return $row->discount->toValueObject();
    }

    private function undiscountedBase(OrderLine $line): Money
    {
        return Money::fromCents($line->unit_price_cents)
            ->multipliedByQuantity(Quantity::fromString($line->qty))
            ->plus(Money::fromCents($line->modifiers_total_cents));
    }
}
