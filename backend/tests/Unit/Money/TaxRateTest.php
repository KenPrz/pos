<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Money\TaxRate;

describe('construction', function (): void {
    it('holds integer micros', function (): void {
        expect(TaxRate::fromMicros(88_750)->micros)->toBe(88_750)
            ->and(TaxRate::zero()->micros)->toBe(0);
    });

    it('parses a percentage without a float', function (string $percent, int $micros): void {
        expect(TaxRate::fromPercent($percent)->micros)->toBe($micros);
    })->with([
        '20% VAT'          => ['20', 200_000],
        'NYC combined'     => ['8.875', 88_750],
        'zero'             => ['0', 0],
        'one dp'           => ['7.5', 75_000],
        'four dp'          => ['1.2345', 12_345],
        '100%'             => ['100', 1_000_000],
    ]);

    it('rejects a rate it cannot represent', function (string $percent): void {
        expect(fn () => TaxRate::fromPercent($percent))->toThrow(InvalidArgumentException::class);
    })->with([['8.87531'], ['-5'], ['abc'], [''], ['5%']]);

    it('refuses a negative rate', function (): void {
        expect(fn () => TaxRate::fromMicros(-1))->toThrow(InvalidArgumentException::class);
    });

    it('round-trips to a display string', function (): void {
        expect(TaxRate::fromPercent('8.875')->toPercentString())->toBe('8.875')
            ->and(TaxRate::fromPercent('20')->toPercentString())->toBe('20')
            ->and(TaxRate::zero()->toPercentString())->toBe('0');
    });
});

describe('tax-exclusive (US retail: added at checkout)', function (): void {
    it('adds tax to a net amount', function (): void {
        // $10.00 at 8.875% -> 88.75c -> 89c, half up.
        $net = Money::fromCents(1000);
        $rate = TaxRate::fromPercent('8.875');

        expect($rate->taxOnNet($net)->cents)->toBe(89);
    });

    it('computes 20% VAT on a net amount', function (): void {
        expect(TaxRate::fromPercent('20')->taxOnNet(Money::fromCents(1000))->cents)->toBe(200);
    });

    it('charges no tax at a zero rate', function (): void {
        expect(TaxRate::zero()->taxOnNet(Money::fromCents(9999))->cents)->toBe(0);
    });
});

describe('tax-inclusive (EU/UK/AU: extracted from the shelf price)', function (): void {
    it('extracts tax from a gross amount', function (): void {
        // £12.00 including 20% VAT: tax = 1200 * 0.2/1.2 = 200. Net 1000.
        $gross = Money::fromCents(1200);
        $rate = TaxRate::fromPercent('20');

        expect($rate->taxOnGross($gross)->cents)->toBe(200)
            ->and($rate->netFromGross($gross)->cents)->toBe(1000);
    });

    it('always splits gross into exactly net + tax', function (): void {
        // The invariant that matters: extraction may never invent or lose a penny,
        // whatever the rate or the amount.
        $rates = ['20', '8.875', '5', '0', '17.5', '7.25'];

        foreach ($rates as $percent) {
            $rate = TaxRate::fromPercent($percent);

            foreach (range(0, 300) as $cents) {
                $gross = Money::fromCents($cents);
                $tax = $rate->taxOnGross($gross);
                $net = $rate->netFromGross($gross);

                expect($net->plus($tax)->cents)->toBe($cents, "{$percent}% of {$cents}c");
            }
        }
    });

    it('extracts nothing at a zero rate', function (): void {
        expect(TaxRate::zero()->taxOnGross(Money::fromCents(1000))->cents)->toBe(0)
            ->and(TaxRate::zero()->netFromGross(Money::fromCents(1000))->cents)->toBe(1000);
    });
});

describe('the two modes agree', function (): void {
    it('reaches the same gross from either direction', function (): void {
        // Price a thing at 1000c net, add 20% -> 1200c gross. Extract 20% from 1200c
        // gross -> 1000c net. A location flipping prices_include_tax must not change
        // what the customer pays for an equivalently-priced item.
        $rate = TaxRate::fromPercent('20');

        $net = Money::fromCents(1000);
        $gross = $net->plus($rate->taxOnNet($net));

        expect($gross->cents)->toBe(1200)
            ->and($rate->netFromGross($gross)->cents)->toBe(1000);
    });
});
