<?php

declare(strict_types=1);

namespace App\Actions\Admin\Locations;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

final class ListLocations
{
    /** @return Collection<int, Location> */
    public function execute(): Collection
    {
        return Location::query()->orderBy('name')->get();
    }
}
