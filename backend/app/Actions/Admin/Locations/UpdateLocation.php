<?php

// backend/app/Actions/Admin/Locations/UpdateLocation.php
declare(strict_types=1);

namespace App\Actions\Admin\Locations;

use App\Domain\Audit\AuditLogger;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

final class UpdateLocation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdateLocationInput $in): Location
    {
        return DB::transaction(function () use ($in): Location {
            $location = Location::query()->lockForUpdate()->findOrFail($in->locationId);

            $location->fill($in->changes)->save();

            $this->audit->record('admin.location.update', $location, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $location;
        });
    }
}
