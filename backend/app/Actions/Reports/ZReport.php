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
     * @param  array<string, int>  $salesByMethod    method code => captured cents
     * @param  array<string, int>  $salesByGroup     group code  => captured cents
     * @param  array<string, int>  $refundsByMethod
     * @param  array<string, int>  $refundsByGroup
     * @param  array{paid_in: int, payout: int, drop: int}  $movements
     */
    public function __construct(
        public Shift $shift,
        public array $salesByMethod,
        public array $salesByGroup,
        public array $refundsByMethod,
        public array $refundsByGroup,
        public array $movements,
        public int $ordersClosed,
        public int $ordersVoided,
        public int $ordersSplit,
        public int $expectedCashCents,
    ) {}
}
