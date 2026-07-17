<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An applied discount on an open order. The row id is what DELETE
 * /orders/{order}/discounts/{discount} takes, so the register can offer removal.
 * amount_cents is the RESOLVED figure the resolver last wrote — never the definition.
 */
final class OrderDiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'discount_id' => $this->discount_id,
            'order_line_id' => $this->order_line_id,
            'name' => $this->name_snapshot,
            'amount_cents' => $this->amount_cents,
            'reason' => $this->reason,
        ];
    }
}
