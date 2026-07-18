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
        // is what filters is_active.
        return Product::query()->orderBy('name')->get();
    }
}
