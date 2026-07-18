<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Collection;

final class ListModifierGroups
{
    /** @return Collection<int, ModifierGroup> */
    public function execute(): Collection
    {
        return ModifierGroup::query()->orderBy('name')->get();
    }
}
