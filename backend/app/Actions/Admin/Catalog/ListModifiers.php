<?php

declare(strict_types=1);

namespace App\Actions\Admin\Catalog;

use App\Models\Modifier;
use Illuminate\Database\Eloquent\Collection;

final class ListModifiers
{
    /** @return Collection<int, Modifier> */
    public function execute(): Collection
    {
        return Modifier::query()->orderBy('group_id')->orderBy('position')->get();
    }
}
