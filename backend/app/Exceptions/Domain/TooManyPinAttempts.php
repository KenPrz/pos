<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Too many failed PINs at one register.
 *
 * Load-bearing rather than decorative: a 4-digit PIN has 10,000 possibilities, which is
 * minutes of brute force without this. The PIN is only an acceptable secret *because* it
 * is presented from an already-enrolled device and rate-limited.
 */
final class TooManyPinAttempts extends DomainException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct("Too many attempts. Try again in {$retryAfterSeconds} seconds.");
    }

    public function errorCode(): string
    {
        return 'too_many_pin_attempts';
    }

    public function httpStatus(): int
    {
        return 429;
    }

    public function details(): array
    {
        return ['retry_after_seconds' => $this->retryAfterSeconds];
    }
}
