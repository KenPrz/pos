<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Money\Tender;
use App\Exceptions\Domain\InsufficientTender;

describe('cash', function (): void {
    it('computes change', function (): void {
        $tender = Tender::cash(Money::fromCents(5000), Money::fromCents(6000));

        expect($tender->applied->cents)->toBe(5000)
            ->and($tender->tendered->cents)->toBe(6000)
            ->and($tender->change->cents)->toBe(1000)
            ->and($tender->givesChange())->toBeTrue();
    });

    it('gives no change on an exact tender', function (): void {
        $tender = Tender::cash(Money::fromCents(5000), Money::fromCents(5000));

        expect($tender->change->isZero())->toBeTrue()
            ->and($tender->givesChange())->toBeFalse();
    });

    it('rejects tendering less than the amount applied', function (): void {
        // Distinct from underpaying: $5 against a $50 bill is a legal partial payment.
        // This is the narrower impossibility of applying $50 when $20 crossed the counter.
        expect(fn () => Tender::cash(Money::fromCents(5000), Money::fromCents(2000)))
            ->toThrow(InsufficientTender::class);
    });

    it('reports insufficient tender with the code and context the API promises', function (): void {
        $exception = new InsufficientTender(Money::fromCents(5000), Money::fromCents(2000));

        expect($exception->errorCode())->toBe('insufficient_tender')
            ->and($exception->httpStatus())->toBe(422)
            ->and($exception->details())->toBe([
                'applied_cents' => 5000,
                'tendered_cents' => 2000,
            ]);
    });

    it('refuses a non-positive amount', function (int $cents): void {
        expect(fn () => Tender::cash(Money::fromCents($cents), Money::fromCents(10_000)))
            ->toThrow(InvalidArgumentException::class);
    })->with([[0], [-100]]);

    it('always balances: applied + change === tendered', function (): void {
        // The register displays what it is told; it never does this subtraction itself.
        // So this identity is the only thing standing between a customer and wrong change.
        foreach (range(1, 150) as $applied) {
            foreach (range(0, 150) as $extra) {
                $tender = Tender::cash(Money::fromCents($applied), Money::fromCents($applied + $extra));

                expect($tender->applied->plus($tender->change)->cents)
                    ->toBe($tender->tendered->cents, "applied {$applied}c, extra {$extra}c")
                    ->and($tender->change->isNegative())->toBeFalse();
            }
        }
    });
});

describe('exact tenders (cards and anything settled to the penny)', function (): void {
    it('never gives change', function (): void {
        $tender = Tender::exact(Money::fromCents(5000));

        expect($tender->applied->cents)->toBe(5000)
            ->and($tender->tendered->cents)->toBe(5000)
            ->and($tender->change->isZero())->toBeTrue();
    });

    it('refuses a non-positive amount', function (): void {
        expect(fn () => Tender::exact(Money::zero()))->toThrow(InvalidArgumentException::class);
    });
});
