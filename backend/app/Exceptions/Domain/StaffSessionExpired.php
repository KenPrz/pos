<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** The staff token is absent, expired, or was issued for a different register. */
final class StaffSessionExpired extends DomainException
{
    public function __construct(string $message = 'Your session has ended. Please enter your PIN again.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'staff_session_expired';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
