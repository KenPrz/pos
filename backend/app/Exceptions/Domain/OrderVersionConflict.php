<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * Optimistic-lock failure: the client's If-Match no longer matches the order's current
 * version. Checked inside the transaction, after lockForUpdate, never in a FormRequest —
 * a request-time check would race the very writers it's meant to catch.
 */
final class OrderVersionConflict extends DomainException
{
    public function __construct(
        private readonly string $orderId,
        private readonly int $expected,
        private readonly int $current,
    ) {
        parent::__construct('The order changed since you read it. Refetch and retry.');
    }

    public function errorCode(): string
    {
        return 'order_version_conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return [
            'order_id' => $this->orderId,
            'expected_version' => $this->expected,
            'current_version' => $this->current,
        ];
    }
}
