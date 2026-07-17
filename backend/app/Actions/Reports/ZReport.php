<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Shift;

/**
 * The drawer's day, read from the ledgers — never from a running total. Works for an
 * open shift (the running X-report) exactly as it does for a closed one; the shift's own
 * counted/expected/variance columns are simply null until close writes them.
 * See docs/02-data-model.md (cash accountability).
 */
final readonly class ZReport
{
    /**
     * @param  array<string, int>  $salesByDriver
     * @param  array<string, int>  $refundsByDriver
     * @param  array{paid_in: int, payout: int, drop: int}  $movements
     */
    public function __construct(
        public Shift $shift,
        public array $salesByDriver,
        public array $refundsByDriver,
        public array $movements,
        public int $ordersClosed,
        public int $ordersVoided,
        public int $expectedCashCents,
    ) {}
}
