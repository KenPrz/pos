<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Models\Shift;

final readonly class CurrentShiftStatus
{
    /** @param array{orders_closed: int, total_cents: int, cash_cents: int} $salesSummary */
    public function __construct(
        public Shift $shift,
        public int $expectedCashCents,
        public array $salesSummary,
    ) {}
}
