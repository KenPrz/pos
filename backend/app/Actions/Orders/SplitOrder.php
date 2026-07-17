<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Money;
use App\Domain\Money\Quantity;
use App\Domain\Orders\OpenOrderLock;
use App\Domain\Orders\OrderNumbers;
use App\Exceptions\Domain\OrderHasPayments;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * Split evenly into N checks — each child gets 1/N of every line as a fractional
 * quantity with allocator-exact money (no column recomputed per child: recomputing
 * 1/N of a tax would mint pennies; Money::allocate is the only splitter).
 *
 * Stock is untouched — it left the ledger when the original lines were added and the
 * children inherit the claim. Which is why the original is closed out as voided
 * WITHOUT restock, written here directly rather than via VoidOrder (which restocks
 * by design). Voided orders are excluded from reporting sums, so nothing double-counts;
 * money truth stays in the payments/refunds ledgers as always.
 */
final class SplitOrder
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly OrderNumbers $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<Order> */
    public function execute(SplitOrderInput $in): array
    {
        return DB::transaction(function () use ($in): array {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            if ($order->paid_cents > 0) {
                throw new OrderHasPayments($order->id, $order->paid_cents);
            }

            $location = Location::findOrFail($order->location_id);
            $lines = $order->lines()->whereNull('voided_at')->with('modifiers')->orderBy('position')->get();
            $discountRows = $order->discounts()->get();   // order- and line-level rows

            $children = [];
            for ($i = 0; $i < $in->ways; $i++) {
                $children[] = Order::create([
                    // Order::$casts deliberately never casts business_date (a local
                    // calendar day, not a datetime) — it is always the raw string
                    // Postgres returns, exactly what OpenOrder passes in directly.
                    'number' => $this->numbers->next($location, $order->business_date),
                    'location_id' => $order->location_id,
                    'register_id' => $order->register_id,
                    'shift_id' => $order->shift_id,
                    'business_date' => $order->business_date,
                    'opened_by' => $order->opened_by,
                    'customer_id' => $order->customer_id,
                    'table_ref' => $order->table_ref,
                    'status' => 'open',
                    'prices_include_tax' => $order->prices_include_tax,
                    'subtotal_cents' => 0, 'discount_cents' => 0, 'tax_cents' => 0,
                    'total_cents' => 0, 'paid_cents' => 0, 'version' => 0,
                    'opened_at' => now(),
                ]);
            }

            foreach ($lines as $ix => $line) {
                $qtyParts = $this->allocateMilli(Quantity::fromString($line->qty)->milli, $in->ways);
                $totalParts = Money::fromCents($line->line_total_cents)->allocate($in->ways);
                $taxParts = Money::fromCents($line->tax_cents)->allocate($in->ways);
                $modParts = Money::fromCents($line->modifiers_total_cents)->allocate($in->ways);
                $discParts = Money::fromCents($line->discount_cents)->allocate($in->ways);

                foreach ($children as $c => $child) {
                    $childLine = $child->lines()->create([
                        'variant_id' => $line->variant_id,
                        'name_snapshot' => $line->name_snapshot,
                        'sku_snapshot' => $line->sku_snapshot,
                        'unit_price_cents' => $line->unit_price_cents,
                        'tax_rate_micros' => $line->tax_rate_micros,
                        'qty' => (string) Quantity::fromMilli($qtyParts[$c]),
                        'modifiers_total_cents' => $modParts[$c]->cents,
                        'discount_cents' => $discParts[$c]->cents,
                        'tax_cents' => $taxParts[$c]->cents,
                        'line_total_cents' => $totalParts[$c]->cents,
                        'prep_state' => $line->prep_state,
                        'position' => $ix,
                        'created_at' => now(),
                    ]);
                    foreach ($line->modifiers as $mod) {
                        $childLine->modifiers()->create([
                            'modifier_id' => $mod->modifier_id,
                            'name_snapshot' => $mod->name_snapshot,
                            'price_delta_cents' => $mod->price_delta_cents,
                        ]);
                    }
                    // line-level discount rows follow their line, allocated
                    foreach ($discountRows->where('order_line_id', $line->id) as $row) {
                        $rowParts = Money::fromCents($row->amount_cents)->allocate($in->ways);
                        if ($rowParts[$c]->isPositive()) {
                            $child->discounts()->create([
                                'order_line_id' => $childLine->id,
                                'discount_id' => $row->discount_id,
                                'name_snapshot' => $row->name_snapshot,
                                'amount_cents' => $rowParts[$c]->cents,
                                'applied_by' => $row->applied_by,
                                'reason' => $row->reason,
                            ]);
                        }
                    }
                }
            }

            foreach ($discountRows->whereNull('order_line_id') as $row) {
                $rowParts = Money::fromCents($row->amount_cents)->allocate($in->ways);
                foreach ($children as $c => $child) {
                    if ($rowParts[$c]->isPositive()) {
                        $child->discounts()->create([
                            'order_line_id' => null,
                            'discount_id' => $row->discount_id,
                            'name_snapshot' => $row->name_snapshot,
                            'amount_cents' => $rowParts[$c]->cents,
                            'applied_by' => $row->applied_by,
                            'reason' => $row->reason,
                        ]);
                    }
                }
            }

            // Child totals are SUMS of allocated parts — never recomputed.
            foreach ($children as $child) {
                $subtotal = (int) $child->lines()->sum('line_total_cents');
                $tax = (int) $child->lines()->sum('tax_cents');
                $discount = (int) $child->discounts()->sum('amount_cents');
                $child->forceFill([
                    'subtotal_cents' => $subtotal,
                    'discount_cents' => $discount,
                    'tax_cents' => $tax,
                    'total_cents' => $child->prices_include_tax ? $subtotal : $subtotal + $tax,
                ])->save();
            }

            $numbers = implode(', ', array_map(fn (Order $c) => $c->number, $children));
            $order->forceFill([
                'status' => OrderStatus::Voided,
                'voided_at' => now(),
                'void_reason' => "split into {$numbers}",
                'version' => $order->version + 1,
            ])->save();

            $childIds = array_map(fn (Order $c) => $c->id, $children);
            $this->audit->record('order.split', $order, $in->actorId, [
                'ways' => $in->ways, 'children' => $childIds,
            ], registerId: $in->registerId);
            foreach ($children as $child) {
                $this->audit->record('order.split.child', $child, $in->actorId, [
                    'parent' => $order->id,
                ], registerId: $in->registerId);
            }

            return array_map(fn (Order $c) => $c->fresh(['lines', 'discounts']), $children);
        });
    }

    /** 1000 milli / 3 → [334, 333, 333]; earliest absorbs, same convention as Money::allocate. @return list<int> */
    private function allocateMilli(int $milli, int $parts): array
    {
        $base = intdiv($milli, $parts);
        $remainder = $milli - $base * $parts;

        return array_map(fn (int $i) => $base + ($i < $remainder ? 1 : 0), range(0, $parts - 1));
    }
}
