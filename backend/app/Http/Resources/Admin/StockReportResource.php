<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the object `StockReport` returns — `{rows}`. Each row is
 * `{variant_id, sku, name, qty, low}`.
 */
final class StockReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['rows' => $this->rows];
    }
}
