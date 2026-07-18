<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\ModifierGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ModifierGroup */
final class AdminModifierGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
