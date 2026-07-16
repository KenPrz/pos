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
 * A signed correction to a variant's on-hand level at the acting register's location:
 * 'adjustment' (either direction) or 'waste' (always negative — the FormRequest rejects
 * a positive delta with reason waste before this ever reaches `StockLedger`, which
 * enforces the same rule with a `LogicException` because a caller mistake there is a bug,
 * not a client error).
 */
final class AdjustStock
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly StockLevels $levels,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(AdjustStockInput $in): object
    {
        return DB::transaction(function () use ($in): object {
            $locationId = Register::findOrFail($in->registerId)->location_id;
            $variant = ProductVariant::findOrFail($in->variantId);

            $this->stock->adjust(
                $variant->id,
                $locationId,
                Quantity::fromString($in->qtyDelta),
                $in->reason,
                $in->actorId,
                $in->note,
            );

            $this->audit->record('stock.adjust', $variant, $in->actorId, [
                'qty_delta' => $in->qtyDelta,
                'reason' => $in->reason,
                'note' => $in->note,
            ], registerId: $in->registerId);

            return $this->levels->current($variant->id, $locationId);
        });
    }
}
