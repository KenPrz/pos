<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property list<Order> $resource */
final class SplitOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['orders' => OrderResource::collection(collect($this->resource))];
    }
}
