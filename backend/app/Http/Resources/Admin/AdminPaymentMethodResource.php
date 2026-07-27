<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tender within a group. `code` and `group_id` are immutable after create — see
 * PaymentMethod's own docblock — so this resource is a plain field mirror, no logic.
 *
 * @mixin PaymentMethod
 */
final class AdminPaymentMethodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'group_id' => $this->group_id,
            'code' => $this->code,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
