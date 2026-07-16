<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'register_id' => $this->register_id,
            'opened_by' => $this->opened_by,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opening_float_cents' => $this->opening_float_cents,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'counted_cash_cents' => $this->counted_cash_cents,
            'expected_cash_cents' => $this->expected_cash_cents,
            'variance_cents' => $this->variance_cents,
        ];
    }
}
