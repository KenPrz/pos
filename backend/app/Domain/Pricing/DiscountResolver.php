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
     * @param  Collection<int, OrderDiscount>  $rows
     * @param  Collection<int, OrderLine>  $lines
     * @param  array<string, Money>  $bases
     */
    private function resolveLineLevelRows(Collection $rows, Collection $lines, array $bases): void
    {
        foreach ($rows->whereNotNull('order_line_id') as $row) {
            $line = $lines->firstWhere('id', $row->order_line_id);

            // Points at a line that is voided (or otherwise not in play): resolves to 0.
            if ($line === null) {
                $row->forceFill(['amount_cents' => 0])->save();

                continue;
            }

            $amount = $this->valueObjectFor($row)->amountFor($bases[$line->id]);
            $row->forceFill(['amount_cents' => $amount->cents])->save();

            $line->discount_cents += $amount->cents;
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

            $lineIds = array_keys($netBases);
            $ratios = array_map(static fn (string $id): int => $netBases[$id]->cents, $lineIds);
            $shares = $amount->allocateByRatios($ratios);

            foreach ($lineIds as $index => $lineId) {
                $line = $lines->firstWhere('id', $lineId);
                $line->discount_cents += $shares[$index]->cents;
                $netBases[$lineId] = $netBases[$lineId]->minus($shares[$index]);
            }
        }
    }

    /**
     * A row with no discount_id is ad-hoc (docs/02-data-model.md): there is no reusable
     * definition to re-resolve against, so it behaves like a fixed discount pinned at its
     * own currently-stored amount — still clamped by the VO against the live base.
     */
    private function valueObjectFor(OrderDiscount $row): DiscountValueObject
    {
        return $row->discount_id !== null
            ? $row->discount->toValueObject()
            : DiscountValueObject::fixed(Money::fromCents($row->amount_cents));
    }

    private function undiscountedBase(OrderLine $line): Money
    {
        return Money::fromCents($line->unit_price_cents)
            ->multipliedByQuantity(Quantity::fromString($line->qty))
            ->plus(Money::fromCents($line->modifiers_total_cents));
    }
}
