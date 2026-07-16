<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** A double close — the shift was already counted and closed. */
final class ShiftAlreadyClosed extends DomainException
{
    public function __construct(private readonly string $shiftId)
    {
        parent::__construct('This shift is already closed.');
    }

    public function errorCode(): string
    {
        return 'shift_already_closed';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['shift_id' => $this->shiftId];
    }
}
