<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** { cash_movement } — docs/03-api.md. */
final class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'cash_movement' => [
                'id' => $this->id,
                'shift_id' => $this->shift_id,
                'kind' => $this->kind,
                'amount_cents' => $this->amount_cents,
                'reason' => $this->reason,
                'created_at' => $this->created_at,
            ],
        ];
    }
}
