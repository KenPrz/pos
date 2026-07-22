<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Location */
final class AdminLocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'timezone' => $this->timezone,
            'prices_include_tax' => $this->prices_include_tax,
            'receipt_header' => $this->receipt_header,
            'receipt_footer' => $this->receipt_footer,
            'is_active' => $this->is_active,
            'variance_approval_threshold_cents' => $this->variance_approval_threshold_cents,
            'low_stock_threshold' => $this->low_stock_threshold,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
