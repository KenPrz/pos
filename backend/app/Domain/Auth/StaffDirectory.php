<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may act at a location, and who holds a given PIN there.
 *
 * "Working at a location" is derived from role assignments — holding a role at a location
 * *is* being assigned there, which is why there is no user_locations pivot able to
 * disagree with it. Admins are included everywhere, because admin is global.
 * See docs/05-rbac.md.
 *
 * These queries hit `model_has_roles` directly rather than going through spatie's
 * `roles()` relation, deliberately: that relation applies
 * `wherePivot(location_id, getPermissionsTeamId())`, and during login there is no team
 * context yet — the team context is a *result* of knowing the register, and we are still
 * working out who is standing at it. Using the relation here would silently match nobody.
 */
final readonly class StaffDirectory
{
    public function __construct(
        private Pins $pins,
    ) {}

    /**
     * The one person at this location with this PIN, if any.
     *
     * Narrows by keyed HMAC (indexed) and *then* verifies against bcrypt, so the hash
     * stays the authority and a lookup collision could never authenticate anyone.
     */
    public function findByPin(string $locationId, string $pin): ?User
    {
        $candidates = $this->activeAt($locationId)
            ->where('pin_lookup', $this->pins->lookup($pin))
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->pins->verify($pin, $candidate->pin_hash)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Would this PIN collide with someone this user shares a location with?
     *
     * Bcrypt is salted, so no unique index can catch this — one of the rare invariants
     * that genuinely cannot live in the schema. It matters because a shared PIN destroys
     * attribution, and an audit log naming the wrong person is worse than useless.
     *
     * @param  list<string>  $locationIds
     */
    public function pinIsTaken(string $pin, array $locationIds, ?string $excludingUserId = null): bool
    {
        if ($locationIds === []) {
            return false;
        }

        $query = User::query()
            ->where('is_active', true)
            ->where('pin_lookup', $this->pins->lookup($pin))
            ->where(fn (Builder $q) => $q
                ->where('is_admin', true)
                ->orWhereExists($this->hasRoleAtAny($locationIds)));

        if ($excludingUserId !== null) {
            $query->whereKeyNot($excludingUserId);
        }

        return $query->exists();
    }

    /** @return Collection<int, User> */
    public function staffAt(string $locationId): Collection
    {
        return $this->activeAt($locationId)->get();
    }

    /** @return Builder<User> */
    private function activeAt(string $locationId): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->where(fn (Builder $q) => $q
                // Admins act everywhere; everyone else needs a role at this location.
                ->where('is_admin', true)
                ->orWhereExists($this->hasRoleAtAny([$locationId])));
    }

    /**
     * @param  list<string>  $locationIds
     * @return callable(QueryBuilder): void
     */
    private function hasRoleAtAny(array $locationIds): callable
    {
        return fn (QueryBuilder $sub) => $sub
            ->select(DB::raw('1'))
            ->from('model_has_roles')
            ->whereColumn('model_has_roles.model_id', 'users.id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('model_has_roles.location_id', $locationIds);
    }
}
