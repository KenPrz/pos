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

        $available = Quantity::fromString($this->lockedLevel($variantId, $locationId)?->qty ?? '0');

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

    /**
     * Stock coming back from a sale context: an order line voided before fulfillment, or a
     * refund line after. `refType` disambiguates the two; both post a positive 'refund' movement.
     */
    public function restock(string $variantId, string $locationId, Quantity $qty, string $refType, string $refId, ?string $userId): void
    {
        $this->assertInTransaction();
        $this->move($variantId, $locationId, $qty, 'refund', $refType, $refId, $userId);
    }

    /**
     * A signed correction: 'adjustment' (either direction) or 'waste' (always negative — a
     * caller passing a positive waste delta is a bug, not a client error). Locks the level
     * like sell() does; never lets it go negative.
     */
    public function adjust(string $variantId, string $locationId, Quantity $delta, string $reason, ?string $userId, ?string $note): void
    {
        $this->assertInTransaction();

        if (! in_array($reason, ['adjustment', 'waste'], true)) {
            throw new LogicException("StockLedger::adjust reason must be 'adjustment' or 'waste', got '{$reason}'.");
        }

        if ($reason === 'waste' && ! $delta->isNegative()) {
            throw new LogicException('StockLedger::adjust waste deltas must be negative.');
        }

        $current = Quantity::fromString($this->lockedLevel($variantId, $locationId)?->qty ?? '0');
        $resulting = $current->plus($delta);

        if ($resulting->isNegative()) {
            throw new InsufficientStock($variantId, (string) $delta->negated(), (string) $current);
        }

        $this->move($variantId, $locationId, $delta, $reason, null, null, $userId, $note);
    }

    /**
     * A physical count overrides the ledger's belief about the level. Locks the level,
     * writes at most one movement (reason 'count') for the difference, and skips the write
     * entirely when the count matches — qty_delta <> 0 is a DB check constraint.
     */
    public function countTo(string $variantId, string $locationId, Quantity $counted, ?string $userId, ?string $note): void
    {
        $this->assertInTransaction();

        $current = Quantity::fromString($this->lockedLevel($variantId, $locationId)?->qty ?? '0');
        $delta = $counted->minus($current);

        if ($delta->isZero()) {
            return;
        }

        $this->move($variantId, $locationId, $delta, 'count', null, null, $userId, $note);
    }

    /** Locks the stock_levels row for the duration of the transaction. Null if it doesn't exist yet. */
    private function lockedLevel(string $variantId, string $locationId): ?object
    {
        return DB::table('stock_levels')
            ->where('variant_id', $variantId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();
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
