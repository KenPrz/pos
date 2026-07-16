<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The same Idempotency-Key was replayed with a different request body.
 *
 * A retried mutation must be byte-identical to the original for a key to be safely
 * reused — otherwise the client meant something else and silently returning the stored
 * response would apply the wrong outcome.
 */
final class IdempotencyKeyReused extends DomainException
{
    public function __construct(private readonly string $key)
    {
        parent::__construct('This Idempotency-Key was already used for a different request.');
    }

    public function errorCode(): string
    {
        return 'idempotency_key_reused';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['key' => $this->key];
    }
}
