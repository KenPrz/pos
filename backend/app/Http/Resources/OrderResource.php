<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every mutating order action returns this whole shape — the register's totals are
 * incapable of drifting because there is no client-side total to be stale.
 */
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'register_id' => $this->register_id,
            'status' => $this->status->value,
            'table_ref' => $this->table_ref,
            'business_date' => $this->business_date,
            'prices_include_tax' => $this->prices_include_tax,
            'subtotal_cents' => $this->subtotal_cents,
            'discount_cents' => $this->discount_cents,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'paid_cents' => $this->paid_cents,
            'version' => $this->version,
            'lines' => OrderLineResource::collection($this->whenLoaded('lines')),
            'discounts' => OrderDiscountResource::collection($this->whenLoaded('discounts')),
        ];
    }
}
