<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** { payment, order } — the change the drawer owes and the state the register renders. */
final class TakePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'payment' => [
                'id' => $this->id,
                'driver' => $this->driver,
                'status' => $this->status,
                'amount_cents' => $this->amount_cents,
                'tendered_cents' => $this->tendered_cents,
                'change_cents' => $this->change_cents,
            ],
            'order' => new OrderResource($this->whenLoaded('order')),
        ];
    }
}
