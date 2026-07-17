<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The discount's scope doesn't match the target of the request: a `line` discount was
 * applied without a line, or an `order` discount was applied with one. Scope is
 * immutable data on the discount row, so this can only be known once the row is loaded —
 * the request layer never touches the database, so it cannot make this call
 * (docs/04-backend-conventions.md). The action checks it, after the lock.
 */
final class DiscountScopeMismatch extends DomainException
{
    public function __construct(private readonly string $discountId, private readonly string $scope)
    {
        parent::__construct("This discount's scope ({$this->scope}) does not match the target of the request.");
    }

    public function errorCode(): string
    {
        return 'discount_scope_mismatch';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['discount_id' => $this->discountId, 'scope' => $this->scope];
    }
}
