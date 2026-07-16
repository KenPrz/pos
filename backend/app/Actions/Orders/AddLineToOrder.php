<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Quantity;
use App\Domain\Pricing\OrderTotals;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * The most demanding action in the system: resolve the location price, snapshot it,
 * lock and decrement stock, recompute totals, bump the version — atomically.
 * The row lock serializes concurrent writers; the version check rejects a stale
 * client. Both, deliberately. (Modifiers arrive in M5.)
 * See the worked example in docs/04-backend-conventions.md.
 */
final class AddLineToOrder
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly StockLedger $stock,
        private readonly OrderTotals $totals,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(AddLineInput $in): OrderLine
    {
        return DB::transaction(function () use ($in): OrderLine {
            // Another location's order is a 404, not a bypass — teams scope permission
            // checks, but record fetches must still be location-scoped by hand (docs/05-rbac.md).
            $locationId = Register::findOrFail($in->registerId)->location_id;
            $order = Order::whereKey($in->orderId)
                ->where('location_id', $locationId)
                ->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Open) {
                throw new OrderClosed($order->id, $order->status->value);
            }

            // Inside the transaction, after the lock — never in the FormRequest.
            if ($order->version !== $in->expectedVersion) {
                throw new OrderVersionConflict($order->id, $in->expectedVersion, $order->version);
            }

            $variant = ProductVariant::query()->active()->with(['product', 'taxRate'])->findOrFail($in->variantId);
            $price = $this->prices->for($variant, $order->location_id);

            // The only moment the live catalog is consulted; the receipt reads these
            // snapshots forever after.
            $line = $order->lines()->create([
                'variant_id' => $variant->id,
                'name_snapshot' => $variant->displayName(),
                'sku_snapshot' => $variant->sku,
                'unit_price_cents' => $price->cents,
                'tax_rate_micros' => $variant->taxRate?->rate_micros ?? 0,
                'qty' => $in->qty,
                'line_total_cents' => 0,   // OrderTotals writes the real value below
                'position' => $order->lines()->count(),
                'created_at' => now(),
            ]);

            if ($variant->track_inventory) {
                $this->stock->sell(
                    $variant->id, $order->location_id, Quantity::fromString($in->qty),
                    refType: 'order_line', refId: $line->id, userId: $in->actorId,
                );
            }

            $this->totals->recalculate($order);
            $order->forceFill(['version' => $order->version + 1])->save();

            $this->audit->record('order.line.add', $line, $in->actorId, [
                'order_id' => $order->id, 'sku' => $line->sku_snapshot, 'qty' => $in->qty,
            ], registerId: $in->registerId);

            return $line->setRelation('order', $order->fresh(['lines']));
        });
    }
}
