<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

final class ListCategories
{
    /** @return Collection<int, Category> */
    public function execute(): Collection
    {
        return Category::query()->orderBy('sort_order')->orderBy('name')->get();
    }
}
