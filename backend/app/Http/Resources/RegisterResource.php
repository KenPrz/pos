<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Register;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Register */
final class RegisterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location_id' => $this->location_id,
            'is_active' => $this->is_active,
        ];
    }
}
