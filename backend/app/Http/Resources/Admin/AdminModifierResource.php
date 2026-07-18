<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Modifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Modifier */
final class AdminModifierResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'name' => $this->name,
            'price_delta_cents' => $this->price_delta_cents,
            'position' => $this->position,
            'is_active' => $this->is_active,
        ];
    }
}
