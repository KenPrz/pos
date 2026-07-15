<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * No active staff member at this register's location has that PIN.
 *
 * Deliberately says nothing about *why*. "No such PIN" versus "wrong PIN for that
 * person" would let anyone with a terminal enumerate valid PINs, and the keyspace is
 * four digits.
 */
final class InvalidPin extends DomainException
{
    public function __construct()
    {
        parent::__construct('That PIN was not recognised.');
    }

    public function errorCode(): string
    {
        return 'invalid_pin';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
