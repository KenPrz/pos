<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * An admin cannot revoke their own access — set their own `is_admin` to false or
 * deactivate their own account — through this endpoint.
 *
 * Without this, an admin editing their own record one field at a time can lock
 * themselves out with no other admin online to undo it. Mirrors `OrderNotZero`.
 */
final class SelfLockout extends DomainException
{
    public function __construct(private readonly string $userId)
    {
        parent::__construct('You cannot remove your own admin access or deactivate your own account.');
    }

    public function errorCode(): string
    {
        return 'self_lockout';
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
