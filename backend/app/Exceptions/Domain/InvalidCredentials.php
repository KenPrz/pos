<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Wrong email, wrong password, deactivated, or non-admin — all answer identically.
 * A distinguishable refusal here is a user-enumeration oracle, so there is no detail.
 */
final class InvalidCredentials extends DomainException
{
    public function __construct()
    {
        parent::__construct('The email or password is incorrect.');
    }

    public function errorCode(): string
    {
        return 'invalid_credentials';
    }

    public function httpStatus(): int
    {
        return 401;
    }

    public function details(): array
    {
        return [];
    }
}
