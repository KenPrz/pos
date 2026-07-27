<?php

declare(strict_types=1);

namespace App\Domain\Payments;

/**
 * A method resolved against one location: what to snapshot onto the ledger row, and
 * which driver to hand to DriverRegistry.
 */
final readonly class ResolvedPaymentMethod
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $groupCode,
        public string $driver,
    ) {}
}
