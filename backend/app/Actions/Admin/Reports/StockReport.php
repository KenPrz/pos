<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reports;

use App\Domain\Money\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * On-hand quantities at a location, `stock_levels` joined to the variant for its SKU and
 * name. Reporting is allowed to read the live catalog for this the same way the sales
 * report's `category` grouping is — nothing here is printed on a receipt.
 *
 * `low` compares against the deployed `pos.stock.low_threshold` in whole milli-units
 * (Quantity's own scale), never as a float, so a fractional on-hand quantity can't
 * miscompare at the boundary. Read-only: no transaction, no audit entry.
 */
final class StockReport
{
    public function execute(StockReportInput $in): object
    {
        $threshold = Quantity::fromString((string) config('pos.stock.low_threshold'));

        $rows = DB::table('stock_levels as sl')
            ->join('product_variants as pv', 'pv.id', '=', 'sl.variant_id')
            ->where('sl.location_id', $in->locationId)
            ->select('sl.variant_id', 'pv.sku', 'pv.name', 'sl.qty')
            ->get()
            ->map(function (object $row) use ($threshold): array {
                $qty = Quantity::fromString((string) $row->qty);

                return [
                    'variant_id' => $row->variant_id,
                    'sku' => $row->sku,
                    'name' => $row->name,
                    'qty' => (string) $qty,
                    'low' => ! $qty->greaterThan($threshold),
                ];
            });

        if ($in->lowOnly) {
            $rows = $rows->filter(fn (array $r): bool => $r['low'])->values();
        }

        return (object) ['rows' => $rows->all()];
    }
}
