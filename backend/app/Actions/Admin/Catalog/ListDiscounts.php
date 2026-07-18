<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Models\Discount;
use Illuminate\Database\Eloquent\Collection;

final class ListDiscounts
{
    /** @return Collection<int, Discount> */
    public function execute(): Collection
    {
        return Discount::query()->orderBy('name')->get();
    }
}
