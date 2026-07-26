<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PaymentMethodGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentMethodGroup */
final class AdminPaymentMethodGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'code' => $this->code,
            'name' => $this->name,
            'driver' => $this->driver,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            // Nested when the list eager-loaded them, so the section renders in one call.
            'methods' => AdminPaymentMethodResource::collection($this->whenLoaded('methods')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
