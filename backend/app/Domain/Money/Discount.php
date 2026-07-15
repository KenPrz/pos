<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * A discount, resolved against a base amount.
 *
 * Mirrors the `discounts` table in docs/02-data-model.md: percent (micros) or fixed
 * (cents), never both. The resolved cents are what get stored on an order — a percentage
 * is not re-evaluated later, so an order gaining a line cannot silently re-scale a
 * discount someone already approved.
 */
final readonly class Discount
{
    private function __construct(
        public DiscountKind $kind,
        public ?int $percentMicros,
        public ?Money $amount,
    ) {}

    /** 10% -> 100_000 micros. */
    public static function percent(int $micros): self
    {
        if ($micros < 0) {
            throw new InvalidArgumentException('A discount cannot be a negative percentage.');
        }

        return new self(DiscountKind::Percent, $micros, null);
    }

    public static function fixed(Money $amount): self
    {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('A fixed discount cannot be negative.');
        }

        return new self(DiscountKind::Fixed, null, $amount);
    }

    /**
     * What this discount actually takes off `$base`.
     *
     * Never exceeds the base and is never negative: a $10 discount on a $5 item takes
     * $5, not −$5. Without the clamp a "generous" discount would turn a sale into a
     * payout, which is a fraud surface, not a rounding detail.
     */
    public function amountFor(Money $base): Money
    {
        if (! $base->isPositive()) {
            return Money::zero();
        }

        $raw = match ($this->kind) {
            DiscountKind::Percent => $base->fraction($this->percentMicros, TaxRate::ONE),
            DiscountKind::Fixed => $this->amount,
        };

        return $raw->min($base)->max(Money::zero());
    }
}
