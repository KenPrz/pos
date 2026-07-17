<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SettleZeroOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order' => new OrderResource($this->resource),
        ];
    }
}
