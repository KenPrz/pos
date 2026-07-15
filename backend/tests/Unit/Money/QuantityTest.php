<?php

declare(strict_types=1);

use App\Domain\Money\Quantity;

describe('parsing', function (): void {
    it('parses to thousandths', function (string $input, int $milli): void {
        expect(Quantity::fromString($input)->milli)->toBe($milli);
    })->with([
        'whole'          => ['3', 3000],
        'half a kilo'    => ['0.5', 500],
        'three decimals' => ['1.234', 1234],
        'zero'           => ['0', 0],
        'trailing zeros' => ['2.500', 2500],
        'negative'       => ['-1.5', -1500],
    ]);

    it('rejects a quantity the column cannot hold', function (string $input): void {
        // numeric(12,3) cannot store a fourth decimal, so accepting it would silently
        // change the number between the register and the database.
        expect(fn () => Quantity::fromString($input))->toThrow(InvalidArgumentException::class);
    })->with([['1.2345'], ['abc'], [''], ['.5'], ['1,5'], ['1e3']]);

    it('cannot be built from a float', function (): void {
        expect(fn () => Quantity::fromMilli(1.5))->toThrow(TypeError::class);
    });
});

describe('formatting', function (): void {
    it('renders the canonical three-decimal wire form', function (string $input, string $expected): void {
        // docs/03-api.md sends quantities as strings, because JS number is IEEE-754 and
        // would corrupt numeric(12,3) in transit.
        expect((string) Quantity::fromString($input))->toBe($expected);
    })->with([
        ['3', '3.000'],
        ['0.5', '0.500'],
        ['1.234', '1.234'],
        ['0', '0.000'],
        ['-1.5', '-1.500'],
    ]);

    it('round-trips any quantity through string form unchanged', function (): void {
        foreach ([-2500, -1, 0, 1, 500, 1234, 999_999] as $milli) {
            $quantity = Quantity::fromMilli($milli);

            expect(Quantity::fromString((string) $quantity)->milli)->toBe($milli);
        }
    });
});

describe('arithmetic', function (): void {
    it('adds and subtracts exactly', function (): void {
        $half = Quantity::fromString('0.5');

        expect($half->plus($half)->milli)->toBe(1000)
            ->and(Quantity::one()->minus($half)->milli)->toBe(500)
            ->and($half->negated()->milli)->toBe(-500);
    });

    it('does not drift when a fractional quantity is added repeatedly', function (): void {
        // 0.1 added ten times is exactly 1 here. In floats it is not.
        $total = Quantity::zero();

        for ($i = 0; $i < 10; $i++) {
            $total = $total->plus(Quantity::fromString('0.1'));
        }

        expect($total->milli)->toBe(1000)
            ->and((string) $total)->toBe('1.000');
    });

    it('compares and knows its sign', function (): void {
        expect(Quantity::one()->greaterThan(Quantity::zero()))->toBeTrue()
            ->and(Quantity::zero()->lessThan(Quantity::one()))->toBeTrue()
            ->and(Quantity::one()->equals(Quantity::fromString('1')))->toBeTrue()
            ->and(Quantity::zero()->isZero())->toBeTrue()
            ->and(Quantity::one()->isPositive())->toBeTrue()
            ->and(Quantity::fromString('-1')->isNegative())->toBeTrue();
    });
});
