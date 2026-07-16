<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'categories' => $this->categories,
            'products' => $this->products,
            'variants' => $this->variants,
            'modifier_groups' => $this->modifierGroups,
            'modifiers' => $this->modifiers,
            'tax_rates' => $this->taxRates,
        ];
    }
}
