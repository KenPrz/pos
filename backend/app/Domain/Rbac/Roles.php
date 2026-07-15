<?php

declare(strict_types=1);

namespace App\Domain\Rbac;

/**
 * The spatie roles. See docs/05-rbac.md.
 *
 * Two, not three. **Admin is not here** — it is `users.is_admin`, granted via
 * Gate::before, because it is the one capability that is genuinely global and spatie's
 * teams cannot express a role assignment that spans locations. The reasoning is on the
 * `is_admin` column in the users migration.
 *
 * Both of these are per-location: a supervisor at one store is not a supervisor at
 * another, which is the entire point.
 */
final class Roles
{
    public const string CASHIER = 'cashier';
    public const string SUPERVISOR = 'supervisor';

    /** Every role, and all of them exist once per location. @return list<string> */
    public static function perLocation(): array
    {
        return [self::CASHIER, self::SUPERVISOR];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::perLocation();
    }

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        return match ($role) {
            self::CASHIER => Permissions::cashier(),
            self::SUPERVISOR => Permissions::supervisor(),
        };
    }
}
