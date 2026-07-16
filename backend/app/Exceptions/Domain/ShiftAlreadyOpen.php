<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Two cashiers raced to open the same register. The partial unique index
 * `one_open_shift_per_register` catches this at the database — this exception is the
 * translation of that constraint violation, not a pre-check.
 * See docs/02-data-model.md (cash accountability).
 */
final class ShiftAlreadyOpen extends DomainException
{
    public function __construct(private readonly string $registerId)
    {
        parent::__construct('This register already has an open shift.');
    }

    public function errorCode(): string
    {
        return 'shift_already_open';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['register_id' => $this->registerId];
    }
}
