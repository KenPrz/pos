<?php

declare(strict_types=1);

namespace App\Actions\Stock;

use App\Domain\Stock\StockLevels;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * The movement history for a variant at the acting register's location, most recent
 * first — "why is my count wrong" answered by selecting rows, per docs/02-data-model.md.
 * Read-only: no transaction, no audit entry.
 */
final class GetStockMovements
{
    private const int LIMIT = 50;

    public function __construct(private readonly StockLevels $levels) {}

    public function execute(string $variantId, string $registerId): object
    {
        $locationId = Register::findOrFail($registerId)->location_id;

        $movements = DB::table('stock_movements')
            ->where('variant_id', $variantId)
            ->where('location_id', $locationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['qty_delta', 'reason', 'ref_type', 'ref_id', 'user_id', 'note', 'created_at']);

        return (object) [
            'movements' => $movements,
            'level' => $this->levels->current($variantId, $locationId),
        ];
    }
}
