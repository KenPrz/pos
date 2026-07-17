<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** { refund } — the original order is never touched, so there is nothing else to return. */
final class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'refund' => [
                'id' => $this->id,
                'original_order_id' => $this->original_order_id,
                'driver' => $this->driver,
                'amount_cents' => $this->amount_cents,
                'reason' => $this->reason,
                'business_date' => $this->business_date,
                'lines' => $this->lines->map(static fn ($line): array => [
                    'original_order_line_id' => $line->original_order_line_id,
                    'qty' => $line->qty,
                    'amount_cents' => $line->amount_cents,
                    'restock' => $line->restock,
                ])->all(),
            ],
        ];
    }
}
