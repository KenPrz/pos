<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name_snapshot,     // snapshots; never the live catalog
            'sku' => $this->sku_snapshot,
            'unit_price_cents' => $this->unit_price_cents,
            'qty' => $this->qty,                // string, always
            'tax_cents' => $this->tax_cents,
            'line_total_cents' => $this->line_total_cents,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'prep_state' => $this->prep_state,
            'modifiers' => $this->modifiers()->get()->map(fn ($m) => [
                'name' => $m->name_snapshot,
                'price_delta_cents' => $m->price_delta_cents,
            ])->all(),
        ];
    }
}
