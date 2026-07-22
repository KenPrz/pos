<?php

declare(strict_types=1);

namespace App\Actions\Admin\Roles;

use App\Models\RoleTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ListRoles
{
    /** @return Collection<int, RoleTemplate> */
    public function execute(): Collection
    {
        $templates = RoleTemplate::query()->with('permissions')->orderBy('name')->get();

        // One grouped query for every template's assignment count — never per-template,
        // or this is an N+1 the moment there's more than a handful of roles.
        $counts = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereNotNull('roles.location_id')
            ->select('roles.name', DB::raw('count(*) as assigned'))
            ->groupBy('roles.name')
            ->pluck('assigned', 'name');

        return $templates->each(function (RoleTemplate $template) use ($counts): void {
            $template->setAttribute('assigned_users', (int) ($counts->get($template->name) ?? 0));
        });
    }
}
