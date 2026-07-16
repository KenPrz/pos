<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * No open shift on this register. Also thrown by order-opening and payment actions
 * (Tasks 8/9) — a sale cannot be attributed to a drawer that isn't running.
 */
final class NoOpenShift extends DomainException
{
    public function __construct(private readonly string $registerId)
    {
        parent::__construct('Open a shift before taking sales.');
    }

    public function errorCode(): string
    {
        return 'no_open_shift';
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
