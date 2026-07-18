<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The DB's `users_can_authenticate` CHECK (`email is not null or pin_hash is not null`)
 * would be violated by this update — e.g. nulling the email of a user with no PIN set.
 *
 * Caught here, before the row is saved, so the failure is a 422 refusal rather than a raw
 * Postgres CHECK-violation 500. Mirrors `SelfLockout`.
 */
final class EmailOrPinRequired extends DomainException
{
    public function __construct(private readonly string $userId)
    {
        parent::__construct('A user must keep at least an email or a PIN to be able to authenticate.');
    }

    public function errorCode(): string
    {
        return 'email_or_pin_required';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['user_id' => $this->userId];
    }
}
