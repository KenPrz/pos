<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
final class AdminProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'kind' => $this->kind,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'modifier_groups' => $this->whenLoaded('modifierGroups', fn () => $this->modifierGroups
                ->map(fn ($group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'position' => (int) $group->pivot->position,
                ])
                ->all()),
            // Plain ordered ids, always present (unlike `modifier_groups` above, which
            // only appears when the caller eager-loaded the pivot) — the back office's
            // attach editor needs this on every list row to seed its checkboxes, not
            // just on create/update/set-groups responses. `relationLoaded` reuses
            // ListProducts' eager load (N+1-free); anything that didn't eager-load
            // (Create/UpdateProduct return a bare model) falls back to one explicit
            // query per row — a method call on the relation, not property access, so it
            // never trips `Model::preventLazyLoading()` in non-production.
            'modifier_group_ids' => ($this->relationLoaded('modifierGroups')
                ? $this->modifierGroups
                : $this->modifierGroups()->orderBy('product_modifier_groups.position')->get()
            )->pluck('id')->all(),
        ];
    }
}
