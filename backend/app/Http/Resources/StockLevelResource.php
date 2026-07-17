<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** { level } — docs/03-api.md. Wraps the object `StockLevels::current()` returns. */
final class StockLevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'level' => [
                'variant_id' => $this->variant_id,
                'qty' => $this->qty,
            ],
        ];
    }
}
