<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Another active staff member at one of this user's locations already has this PIN.
 *
 * Bcrypt hashes are salted, so no unique index can catch this — it is one of the rare
 * invariants that genuinely cannot live in the schema. It matters because two people
 * sharing a PIN destroys attribution: the audit log would name the wrong person, which
 * is worse than useless in a dispute. See docs/05-rbac.md.
 */
final class PinAlreadyInUse extends DomainException
{
    public function __construct()
    {
        parent::__construct('That PIN is already in use at this location. Choose another.');
    }

    public function errorCode(): string
    {
        return 'pin_already_in_use';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
