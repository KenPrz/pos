<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The close response: docs/03-api.md promises the reconciliation numbers top-level. */
final class CloseShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'shift' => new ShiftResource($this->resource),
            'expected_cash_cents' => $this->expected_cash_cents,
            'variance_cents' => $this->variance_cents,
            'requires_approval' => abs($this->variance_cents) > config('pos.shifts.variance_approval_threshold_cents'),
        ];
    }
}
