<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

final class ListProducts
{
    /** @return Collection<int, Product> */
    public function execute(): Collection
    {
        // Include archived — the back office sees everything; the register's GetCatalog
        // is what filters is_active. Eager-load modifierGroups so AdminProductResource's
        // `modifier_group_ids` reads the preloaded (already position-ordered) collection
        // instead of running one query per row.
        return Product::query()->with('modifierGroups')->orderBy('name')->get();
    }
}
