<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps CurrentShiftStatus (Actions/Shifts). */
final class CurrentShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'shift' => new ShiftResource($this->shift),
            'expected_cash_cents' => $this->expectedCashCents,
            'sales_summary' => $this->salesSummary,
        ];
    }
}
