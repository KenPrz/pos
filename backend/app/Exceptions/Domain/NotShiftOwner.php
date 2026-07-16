<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * docs/05-rbac.md: a cashier closes their own shift; a supervisor may close anyone's.
 * `shift.close` cannot express "own" — that's a Policy question, not a permission one.
 */
final class NotShiftOwner extends DomainException
{
    public function __construct(private readonly string $shiftId, private readonly string $openedBy)
    {
        parent::__construct("Only the shift's opener or a supervisor can close it.");
    }

    public function errorCode(): string
    {
        return 'requires_supervisor';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['shift_id' => $this->shiftId, 'opened_by' => $this->openedBy];
    }
}
