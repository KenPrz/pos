<?php

// backend/app/Actions/Shifts/ListOpenShiftRegisters.php
declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Models\Register;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Other active registers at the acting register's location that currently have an open
 * shift, plus who opened each one — the register app uses this to offer "approve this
 * variance from a register that's still open" and similar cross-till flows.
 */
final class ListOpenShiftRegisters
{
    /** @return Collection<int, object{register_id: string, register_name: string, shift_id: string, opened_by_name: string}> */
    public function execute(string $actingRegisterId): Collection
    {
        $locationId = Register::query()->findOrFail($actingRegisterId)->location_id;

        return DB::table('registers as r')
            ->join('shifts as s', fn ($j) => $j->on('s.register_id', '=', 'r.id')->whereNull('s.closed_at'))
            ->join('users as u', 'u.id', '=', 's.opened_by')
            ->where('r.location_id', $locationId)
            ->where('r.is_active', true)
            ->where('r.id', '!=', $actingRegisterId)
            ->orderBy('r.name')
            ->get(['r.id as register_id', 'r.name as register_name', 's.id as shift_id', 'u.name as opened_by_name']);
    }
}
