<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The presented bearer token does not belong to an active admin user.
 *
 * Thrown by EnsureAdmin rather than the middleware building JSON inline, mirroring
 * EnsureDeviceToken/InvalidDeviceToken and EnsureStaffSession/StaffSessionExpired: every
 * refusal in the auth-gate middlewares is a DomainException, rendered once by
 * ApiErrorEnvelope.
 */
final class AdminAccessRequired extends DomainException
{
    public function __construct()
    {
        parent::__construct('Admin access required.');
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return [];
    }
}
