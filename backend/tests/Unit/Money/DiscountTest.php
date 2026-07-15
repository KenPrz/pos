<?php

declare(strict_types=1);

use App\Domain\Money\Discount;
use App\Domain\Money\Money;

describe('percent discounts', function (): void {
    it('takes a percentage off', function (): void {
        // 10% of $50.00
        expect(Discount::percent(100_000)->amountFor(Money::fromCents(5000))->cents)->toBe(500);
    });

    it('rounds half up, like every other percentage', function (): void {
        // 10% of 5c = 0.5c -> 1c
        expect(Discount::percent(100_000)->amountFor(Money::fromCents(5))->cents)->toBe(1);
    });

    it('refuses a negative percentage', function (): void {
        expect(fn () => Discount::percent(-1))->toThrow(InvalidArgumentException::class);
    });
});

describe('fixed discounts', function (): void {
    it('takes a fixed amount off', function (): void {
        expect(Discount::fixed(Money::fromCents(250))->amountFor(Money::fromCents(1000))->cents)->toBe(250);
    });

    it('refuses a negative amount', function (): void {
        expect(fn () => Discount::fixed(Money::fromCents(-1)))->toThrow(InvalidArgumentException::class);
    });
});

describe('the clamp', function (): void {
    it('never discounts more than the base', function (): void {
        // A $10 discount on a $5 item takes $5, not -$5. Without this a "generous"
        // discount turns a sale into a payout — a fraud surface, not a rounding detail.
        expect(Discount::fixed(Money::fromCents(1000))->amountFor(Money::fromCents(500))->cents)->toBe(500);
    });

    it('caps a percentage over 100% at the base', function (): void {
        expect(Discount::percent(2_000_000)->amountFor(Money::fromCents(500))->cents)->toBe(500);
    });

    it('discounts nothing off a zero or negative base', function (): void {
        expect(Discount::percent(100_000)->amountFor(Money::zero())->cents)->toBe(0)
            ->and(Discount::fixed(Money::fromCents(100))->amountFor(Money::zero())->cents)->toBe(0)
            ->and(Discount::fixed(Money::fromCents(100))->amountFor(Money::fromCents(-500))->cents)->toBe(0);
    });

    it('never returns a negative amount, for any input', function (): void {
        foreach (range(0, 200) as $base) {
            foreach ([0, 1, 50_000, 100_000, 1_000_000, 5_000_000] as $micros) {
                $amount = Discount::percent($micros)->amountFor(Money::fromCents($base));

                expect($amount->isNegative())->toBeFalse()
                    ->and($amount->cents)->toBeLessThanOrEqual($base);
            }
        }
    });
});
