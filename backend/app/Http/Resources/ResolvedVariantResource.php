<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ResolvedVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'variant' => [
                'id' => $this->variant->id,
                'product_id' => $this->variant->product_id,
                'name' => $this->variant->displayName(),
                'sku' => $this->variant->sku,
                'barcode' => $this->variant->barcode,
                'price_cents' => $this->price->cents,
                'track_inventory' => $this->variant->track_inventory,
            ],
        ];
    }
}
