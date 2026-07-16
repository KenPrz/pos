<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Domain\Shifts\ShiftTotals;
use App\Models\Shift;

final class GetCurrentShift
{
    public function __construct(private readonly ShiftTotals $totals) {}

    public function execute(string $registerId): CurrentShiftStatus
    {
        $shift = Shift::where('register_id', $registerId)->whereNull('closed_at')->firstOrFail();

        return new CurrentShiftStatus(
            shift: $shift,
            expectedCashCents: $this->totals->expectedCashCents($shift),
            salesSummary: $this->totals->salesSummary($shift),
        );
    }
}
