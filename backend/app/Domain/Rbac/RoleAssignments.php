<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads and writes per-location role assignments in `model_has_roles`, directly.
 *
 * Never spatie's `roles()` relation: it applies `wherePivot(location_id, currentTeam)`,
 * so it can only ever answer "roles at the location I'm already standing at" — not
 * "roles". See docs/05-rbac.md and the CLAUDE.md gotcha this is named after.
 *
 * The real column names come from the published permission migration
 * (`database/migrations/*create_permission_tables*.php`), which customized both for our
 * uuid keys: the morph key is `model_id` (not `model_uuid`), and the team column is
 * `location_id`. `roles` is also team-scoped: with spatie teams enabled, `cashier` is a
 * *different row* per location (see `RoleProvisioner`), so resolving a role id by name
 * alone would silently pick an arbitrary location's row — the assignment would then fail
 * `HasRoles::roles()`'s own team filter (it requires the role's own team column to match
 * or be null) and the user would silently have no permissions at that location.
 *
 * One home for the sync invariant because it has two callers — `CreateUser` and
 * `UpdateUser` — and it is real logic, not boilerplate worth duplicating.
 */
final class RoleAssignments
{
    /**
     * Full-set replace: delete this user's assignments, insert the new set.
     *
     * @param  list<array{location_id: string, role: string}>  $roles
     * @return list<array{location_id: string, from: ?string, to: ?string}> changed locations only
     */
    public function sync(User $user, array $roles): array
    {
        $before = $this->current($user);

        DB::table('model_has_roles')
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->id)
            ->delete();

        foreach ($roles as $assignment) {
            $roleId = DB::table('roles')
                ->where('name', $assignment['role'])
                ->where('guard_name', RoleProvisioner::GUARD)
                ->where('location_id', $assignment['location_id'])
                ->value('id')
                // A role that isn't provisioned at this location is a seeder/deploy bug —
                // RoleProvisioner::provisionForLocation() should have run for every
                // location before it can take staff. `model_has_roles.role_id` is
                // NOT NULL, so leaving this unguarded would still fail, just as a
                // confusing constraint-violation 500 instead of a clear one. Loud beats
                // silently assigning nothing.
                ?? throw new RuntimeException("Role \"{$assignment['role']}\" is not provisioned at location {$assignment['location_id']}.");

            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => $user->getMorphClass(),
                'model_id' => $user->id,
                'location_id' => $assignment['location_id'],
            ]);
        }

        return $this->diff($before, $roles);
    }

    /** @return list<array{location_id: string, role: string}> */
    public function current(User $user): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->id)
            ->select('model_has_roles.location_id', 'roles.name as role')
            ->get()
            ->map(fn (object $row): array => ['location_id' => $row->location_id, 'role' => $row->role])
            ->all();
    }

    /** @return list<array{location_id: string, location_name: string, role: string}> */
    public function describe(User $user): array
    {
        return $this->rows(fn ($q) => $q->where('model_has_roles.model_id', $user->id))->all();
    }

    /**
     * Bulk form of `describe()`, one query for every user in the list — the read side of
     * `ListUsers` needs the same join per user; doing it per-user would be an N+1.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<string, list<array{location_id: string, location_name: string, role: string}>> keyed by user id
     */
    public function describeMany(Collection $users): Collection
    {
        if ($users->isEmpty()) {
            return collect();
        }

        return $this->rows(fn ($q) => $q->whereIn('model_has_roles.model_id', $users->pluck('id')), withUserId: true)
            ->groupBy('user_id')
            ->map(fn (Collection $rows): array => $rows->map(
                fn (array $row): array => ['location_id' => $row['location_id'], 'location_name' => $row['location_name'], 'role' => $row['role']]
            )->values()->all());
    }

    /**
     * @param  callable(Builder): Builder  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(callable $scope, bool $withUserId = false): Collection
    {
        $query = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('locations', 'locations.id', '=', 'model_has_roles.location_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->select([
                'model_has_roles.location_id',
                'locations.name as location_name',
                'roles.name as role',
                ...($withUserId ? ['model_has_roles.model_id as user_id'] : []),
            ]);

        return $scope($query)->get()->map(fn (object $row): array => (array) $row);
    }

    /**
     * @param  list<array{location_id: string, role: string}>  $before
     * @param  list<array{location_id: string, role: string}>  $after
     * @return list<array{location_id: string, from: ?string, to: ?string}>
     */
    private function diff(array $before, array $after): array
    {
        $beforeByLocation = collect($before)->keyBy('location_id');
        $afterByLocation = collect($after)->keyBy('location_id');

        $changes = [];
        foreach ($beforeByLocation->keys()->merge($afterByLocation->keys())->unique() as $locationId) {
            $from = $beforeByLocation->get($locationId)['role'] ?? null;
            $to = $afterByLocation->get($locationId)['role'] ?? null;

            if ($from !== $to) {
                $changes[] = ['location_id' => $locationId, 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }
}
