<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;

final class ListVariants
{
    /** @return Collection<int, ProductVariant> */
    public function execute(): Collection
    {
        // Include archived and (thanks to withTrashed) soft-deleted rows — the back
        // office sees everything; the register's GetCatalog is what filters.
        return ProductVariant::withTrashed()->orderBy('name')->get();
    }
}
