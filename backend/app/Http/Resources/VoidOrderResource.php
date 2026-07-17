<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** { order } — docs/03-api.md. Every order mutation returns the whole order. */
final class VoidOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order' => new OrderResource($this->resource),
        ];
    }
}
