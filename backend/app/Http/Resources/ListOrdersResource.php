<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class ListOrdersResource extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'orders' => OrderResource::collection($this->collection),
        ];
    }
}
