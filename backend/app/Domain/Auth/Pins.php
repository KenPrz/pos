<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Illuminate\Support\Facades\Hash;

/**
 * Hashing and lookup for staff PINs.
 *
 * Two representations of the same secret, doing different jobs:
 *
 *  - `pin_hash` (bcrypt, salted) is the **authority**. Nothing authenticates without a
 *    verify against it.
 *  - `pin_lookup` (keyed HMAC, deterministic) is an **index**. It exists only so login
 *    can find the candidate without bcrypt-checking every member of staff — measured at
 *    225ms each, twenty staff is a 4.5-second login.
 *
 * The key is injected rather than read from config here, so this stays a pure
 * collaborator and its tests need no container. See docs/04-backend-conventions.md.
 */
final readonly class Pins
{
    public function __construct(
        private string $key,
    ) {}

    /** Deterministic and keyed: useless to anyone holding the database but not APP_KEY. */
    public function lookup(string $pin): string
    {
        return hash_hmac('sha256', $pin, $this->key);
    }

    public function hash(string $pin): string
    {
        return Hash::make($pin);
    }

    public function verify(string $pin, ?string $hash): bool
    {
        if ($hash === null) {
            return false;
        }

        return Hash::check($pin, $hash);
    }
}
