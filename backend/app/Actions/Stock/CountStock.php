<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Quantity;
use App\Domain\Stock\StockLedger;
use App\Domain\Stock\StockLevels;
use App\Models\ProductVariant;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * A physical count overrides the ledger's belief about the level at the acting
 * register's location. `StockLedger::countTo` writes at most one 'count' movement — none
 * at all when the count matches — so the delta returned here comes from that row, the
 * same source `GetStockMovements` reads, rather than a second, potentially-drifting
 * computation.
 */
final class CountStock
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly StockLevels $levels,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(CountStockInput $in): object
    {
        return DB::transaction(function () use ($in): object {
            $locationId = Register::findOrFail($in->registerId)->location_id;
            $variant = ProductVariant::findOrFail($in->variantId);

            $this->stock->countTo($variant->id, $locationId, Quantity::fromString($in->countedQty), $in->actorId, $in->note);

            $delta = DB::table('stock_movements')
                ->where('variant_id', $variant->id)
                ->where('location_id', $locationId)
                ->where('reason', 'count')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('qty_delta') ?? '0.000';

            $this->audit->record('stock.count', $variant, $in->actorId, [
                'counted_qty' => $in->countedQty,
                'delta' => $delta,
                'note' => $in->note,
            ], registerId: $in->registerId);

            return (object) [
                'level' => $this->levels->current($variant->id, $locationId),
                'delta' => $delta,
            ];
        });
    }
}
