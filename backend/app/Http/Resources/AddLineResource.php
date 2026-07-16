<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * { order, line } — docs/03-api.md. The whole order comes back on every line mutation,
 * so the register's totals are incapable of drifting from the server's.
 */
final class AddLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order' => new OrderResource($this->order),
            'line' => new OrderLineResource($this->resource),
        ];
    }
}
