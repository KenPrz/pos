<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Money\Quantity;
use App\Exceptions\Domain\InsufficientStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Stock is a ledger, not a number. Every change is an immutable stock_movements row
 * plus an update to the cached stock_levels row, in the SAME transaction — the
 * invariant is stock_levels.qty = sum(stock_movements.qty_delta).
 *
 * Selling locks the level row (SELECT ... FOR UPDATE): pessimistic on purpose, because
 * "never sell what we don't have" can't be promised by optimistic retry.
 * See docs/02-data-model.md (inventory).
 */
final class StockLedger
{
    public function sell(string $variantId, string $locationId, Quantity $qty, string $refType, string $refId, ?string $userId): void
    {
        $this->assertInTransaction();

        $level = DB::table('stock_levels')
            ->where('variant_id', $variantId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        $available = Quantity::fromString($level->qty ?? '0');

        if ($qty->greaterThan($available)) {
            throw new InsufficientStock($variantId, (string) $qty, (string) $available);
        }

        $this->move($variantId, $locationId, $qty->negated(), 'sale', $refType, $refId, $userId);
    }

    /** Deliveries and seed data. Creates the level row if it doesn't exist yet. */
    public function receive(string $variantId, string $locationId, Quantity $qty, ?string $userId = null, ?string $note = null): void
    {
        $this->assertInTransaction();
        $this->move($variantId, $locationId, $qty, 'receive', null, null, $userId, $note);
    }

    private function move(string $variantId, string $locationId, Quantity $delta, string $reason, ?string $refType, ?string $refId, ?string $userId, ?string $note = null): void
    {
        // Ledger row first, then the cache it derives.
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid7(),
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'qty_delta' => (string) $delta,
            'reason' => $reason,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'user_id' => $userId,
            'note' => $note,
            'created_at' => now(),
        ]);

        DB::statement(
            'insert into stock_levels (variant_id, location_id, qty, updated_at)
             values (?, ?, ?, now())
             on conflict (variant_id, location_id)
             do update set qty = stock_levels.qty + excluded.qty, updated_at = now()',
            [$variantId, $locationId, (string) $delta],
        );
    }

    private function assertInTransaction(): void
    {
        // The movement and the level must commit together; silently running outside a
        // transaction would make the invariant breakable by any caller mistake.
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockLedger must be called inside a transaction.');
        }
    }
}
