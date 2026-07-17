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

/** A delivery arriving at the acting register's location. Creates the level row if needed. */
final class ReceiveStock
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly StockLevels $levels,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(ReceiveStockInput $in): object
    {
        return DB::transaction(function () use ($in): object {
            $locationId = Register::findOrFail($in->registerId)->location_id;
            $variant = ProductVariant::findOrFail($in->variantId);

            $this->stock->receive($variant->id, $locationId, Quantity::fromString($in->qty), $in->actorId, $in->note);

            $this->audit->record('stock.receive', $variant, $in->actorId, [
                'qty' => $in->qty,
                'note' => $in->note,
            ], registerId: $in->registerId);

            return $this->levels->current($variant->id, $locationId);
        });
    }
}
