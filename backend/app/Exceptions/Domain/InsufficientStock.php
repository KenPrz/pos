<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The requested quantity exceeds what stock_levels says is on hand at this location.
 *
 * Thrown by StockLedger::sell() while holding the level row's lock, so "available" is the
 * true, contended value at the instant of decision — not a stale read from before the lock.
 */
final class InsufficientStock extends DomainException
{
    public function __construct(
        private readonly string $variantId,
        private readonly string $requested,
        private readonly string $available,
    ) {
        parent::__construct("Only {$available} units remain.");
    }

    public function errorCode(): string
    {
        return 'insufficient_stock';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return [
            'variant_id' => $this->variantId,
            'requested' => $this->requested,
            'available' => $this->available,
        ];
    }
}
