<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use Illuminate\Support\Facades\DB;

/**
 * Reads the cached `stock_levels` projection — the "what's on hand right now" half of
 * the ledger in `StockLedger`. Shared by every Stock action that needs to hand a level
 * back to the client after writing a movement, and by `GetStockMovements` for the
 * read-only "why is my count wrong" query. See docs/02-data-model.md.
 *
 * Deliberately separate from `StockLedger`: every method there asserts it's inside a
 * transaction because it mutates; this only reads, so it must not carry that assertion.
 */
final class StockLevels
{
    /** The current level at a location, or '0.000' if the row doesn't exist yet. */
    public function current(string $variantId, string $locationId): object
    {
        $qty = DB::table('stock_levels')
            ->where('variant_id', $variantId)
            ->where('location_id', $locationId)
            ->value('qty');

        return (object) [
            'variant_id' => $variantId,
            'qty' => $qty ?? '0.000',
        ];
    }
}
