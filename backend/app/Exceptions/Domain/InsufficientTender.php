<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Domain\Money\Money;

/**
 * The customer handed over less than the amount being applied to the order.
 *
 * Not the same as underpaying: paying $5 against a $50 bill is a partial payment and
 * perfectly legal (docs/03-api.md). This is the narrower impossibility of applying $50
 * to an order when only $20 was physically handed across the counter.
 */
final class InsufficientTender extends DomainException
{
    public function __construct(
        private readonly Money $applied,
        private readonly Money $tendered,
    ) {
        parent::__construct('The amount tendered is less than the amount being applied.');
    }

    public function errorCode(): string
    {
        return 'insufficient_tender';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [
            'applied_cents' => $this->applied->cents,
            'tendered_cents' => $this->tendered->cents,
        ];
    }
}
