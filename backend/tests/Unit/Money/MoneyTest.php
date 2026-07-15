<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Money\Quantity;

/*
| Pure integer functions: no container, no database, no HTTP. These run in milliseconds
| and are where the expensive bugs live. See docs/01-architecture.md.
*/

describe('construction', function (): void {
    it('holds integer cents', function (): void {
        expect(Money::fromCents(1234)->cents)->toBe(1234)
            ->and(Money::zero()->cents)->toBe(0)
            ->and(Money::fromCents(-500)->cents)->toBe(-500);
    });

    it('cannot be built from a float', function (): void {
        // The whole discipline in one assertion. Under strict_types this is a TypeError,
        // so `Money::fromCents(12.34)` is unrepresentable rather than merely discouraged.
        expect(fn () => Money::fromCents(12.34))->toThrow(TypeError::class);
    });

    it('exposes no float anywhere in its API', function (): void {
        $reflection = new ReflectionClass(Money::class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                expect((string) $parameter->getType())
                    ->not->toContain('float', "Money::{$method->getName()} accepts a float");
            }

            expect((string) $method->getReturnType())
                ->not->toContain('float', "Money::{$method->getName()} returns a float");
        }
    });
});

describe('parsing', function (): void {
    it('parses decimal strings without touching a float', function (string $input, int $expected): void {
        expect(Money::parse($input)->cents)->toBe($expected);
    })->with([
        ['0', 0],
        ['12.34', 1234],
        ['12.3', 1230],
        ['12', 1200],
        ['-4.05', -405],
        ['1000000.99', 100000099],
        // (int) (float) '1.15' * 100 famously gives 114. String parsing gives 115.
        ['1.15', 115],
        ['1.16', 116],
        ['0.07', 7],
    ]);

    it('rejects anything it cannot represent exactly', function (string $input): void {
        expect(fn () => Money::parse($input))->toThrow(InvalidArgumentException::class);
    })->with([
        // Three decimals would mean silently discarding a digit — that is losing money.
        ['1.234'],
        ['abc'],
        [''],
        ['1.2.3'],
        ['1,234.00'],
        ['$5.00'],
        ['1e3'],
        ['.5'],
    ]);
});

describe('arithmetic', function (): void {
    it('adds, subtracts and multiplies exactly', function (): void {
        expect(Money::fromCents(1000)->plus(Money::fromCents(234))->cents)->toBe(1234)
            ->and(Money::fromCents(1000)->minus(Money::fromCents(1))->cents)->toBe(999)
            ->and(Money::fromCents(333)->multipliedBy(3)->cents)->toBe(999)
            ->and(Money::fromCents(100)->negated()->cents)->toBe(-100)
            ->and(Money::fromCents(-100)->absolute()->cents)->toBe(100);
    });

    it('never loses a cent to floating point, however many times you add it', function (): void {
        // The canonical float failure: 0.1 + 0.2 !== 0.3. In cents it simply cannot happen.
        $total = Money::zero();

        for ($i = 0; $i < 1000; $i++) {
            $total = $total->plus(Money::fromCents(10));
        }

        expect($total->cents)->toBe(10_000);
    });

    it('sums a list', function (): void {
        $sum = Money::sum([Money::fromCents(100), Money::fromCents(250), Money::fromCents(-50)]);

        expect($sum->cents)->toBe(300)
            ->and(Money::sum([])->cents)->toBe(0);
    });
});

describe('fraction (the single rounding primitive)', function (): void {
    it('rounds half away from zero', function (int $cents, int $n, int $d, int $expected): void {
        expect(Money::fromCents($cents)->fraction($n, $d)->cents)->toBe($expected);
    })->with([
        // Exact, no rounding needed.
        'exact half'        => [1000, 1, 2, 500],
        'exact third'       => [999, 1, 3, 333],
        // The .5 boundary rounds up, not to even. 5/2 = 2.5 -> 3.
        'half rounds up'    => [5, 1, 2, 3],
        'half rounds up 2'  => [3, 1, 2, 2],
        // Just below and above the boundary.
        'below half'        => [10, 49, 100, 5],   // 4.9 -> 5
        'above half'        => [10, 51, 100, 5],   // 5.1 -> 5
        'clearly down'      => [10, 44, 100, 4],   // 4.4 -> 4
        // Negatives round away from zero too: -2.5 -> -3.
        'negative half'     => [-5, 1, 2, -3],
        'negative third'    => [-999, 1, 3, -333],
    ]);

    it('refuses to divide by zero', function (): void {
        expect(fn () => Money::fromCents(100)->fraction(1, 0))->toThrow(InvalidArgumentException::class);
    });

    it('refuses to overflow rather than silently becoming a float', function (): void {
        // PHP promotes integer overflow to float, which is exactly the thing this class
        // exists to prevent. Unreachable with real money, but it fails with a reason.
        expect(fn () => Money::fromCents(PHP_INT_MAX)->fraction(1000, 1))
            ->toThrow(InvalidArgumentException::class, 'integer overflow');
    });

    it('has ample headroom for any real amount', function (): void {
        // $10M at 100% — four orders of magnitude below PHP_INT_MAX.
        expect(Money::fromCents(1_000_000_000)->fraction(1_000_000, 1_000_000)->cents)
            ->toBe(1_000_000_000);
    });
});

describe('quantity multiplication', function (): void {
    it('rounds a fractional quantity per line, half up', function (string $qty, int $unitPrice, int $expected): void {
        expect(Money::fromCents($unitPrice)->multipliedByQuantity(Quantity::fromString($qty))->cents)
            ->toBe($expected);
    })->with([
        'whole units'      => ['3', 333, 999],
        'half a kilo'      => ['0.5', 1000, 500],
        // 333 * 0.5 = 166.5 -> 167. Rounded here, on the line, not accumulated.
        'rounds up at .5'  => ['0.5', 333, 167],
        'three decimals'   => ['1.234', 1000, 1234],
        'zero'             => ['0', 500, 0],
    ]);
});

describe('allocation', function (): void {
    it('splits 1000 three ways as 334, 333, 333', function (): void {
        // The earliest part absorbs the remainder. The rule is arbitrary; that it is
        // deterministic and totals exactly is not. See docs/01-architecture.md.
        $parts = array_map(fn (Money $m): int => $m->cents, Money::fromCents(1000)->allocate(3));

        expect($parts)->toBe([334, 333, 333])
            ->and(array_sum($parts))->toBe(1000);
    });

    it('splits evenly when it divides evenly', function (): void {
        $parts = array_map(fn (Money $m): int => $m->cents, Money::fromCents(900)->allocate(3));

        expect($parts)->toBe([300, 300, 300]);
    });

    it('allocates by ratios for prorating an order discount across lines', function (): void {
        // A 100c discount across lines worth 2:1 -> 67/33, summing to exactly 100.
        $parts = array_map(fn (Money $m): int => $m->cents, Money::fromCents(100)->allocateByRatios([2, 1]));

        expect($parts)->toBe([67, 33])
            ->and(array_sum($parts))->toBe(100);
    });

    it('handles a zero-weight line without inventing a penny', function (): void {
        $parts = array_map(fn (Money $m): int => $m->cents, Money::fromCents(100)->allocateByRatios([1, 0, 1]));

        expect(array_sum($parts))->toBe(100)
            ->and($parts[1])->toBe(0);
    });

    it('rejects nonsense', function (callable $call): void {
        expect($call)->toThrow(InvalidArgumentException::class);
    })->with([
        'zero parts'      => [fn () => Money::fromCents(100)->allocate(0)],
        'negative parts'  => [fn () => Money::fromCents(100)->allocate(-1)],
        'no ratios'       => [fn () => Money::fromCents(100)->allocateByRatios([])],
        'ratios sum zero' => [fn () => Money::fromCents(100)->allocateByRatios([0, 0])],
        'negative ratio'  => [fn () => Money::fromCents(100)->allocateByRatios([1, -1])],
    ]);

    /*
    | The property test the roadmap calls for. Splitting a bill is the one place a POS
    | invents or destroys money in front of a customer, so "the parts always sum to the
    | whole" is asserted exhaustively rather than at a few hand-picked points.
    */
    it('always sums to the whole, for every amount and every split', function (): void {
        foreach (range(0, 200) as $cents) {
            foreach (range(1, 12) as $parts) {
                $split = Money::fromCents($cents)->allocate($parts);

                expect(Money::sum($split)->cents)->toBe($cents, "{$cents}c into {$parts} parts")
                    ->and($split)->toHaveCount($parts);

                // No part may differ from another by more than a single cent, or the
                // split is "fair" only in the sense that it adds up.
                $values = array_map(fn (Money $m): int => $m->cents, $split);
                expect(max($values) - min($values))->toBeLessThanOrEqual(1);
            }
        }
    });

    it('always sums to the whole for negative amounts too', function (): void {
        // Refunds allocate as well, and truncation flips direction below zero.
        foreach (range(-200, -1) as $cents) {
            foreach (range(1, 7) as $parts) {
                expect(Money::sum(Money::fromCents($cents)->allocate($parts))->cents)
                    ->toBe($cents, "{$cents}c into {$parts} parts");
            }
        }
    });

    it('always sums to the whole when allocating by arbitrary ratios', function (): void {
        mt_srand(20260716);   // deterministic: a failing case must be reproducible

        for ($i = 0; $i < 500; $i++) {
            $cents = mt_rand(0, 100_000);
            $ratios = [];

            foreach (range(1, mt_rand(1, 6)) as $ignored) {
                $ratios[] = mt_rand(0, 50);
            }

            if (array_sum($ratios) === 0) {
                continue;
            }

            expect(Money::sum(Money::fromCents($cents)->allocateByRatios($ratios))->cents)
                ->toBe($cents, "{$cents}c by ratios ".implode(':', $ratios));
        }
    });
});

describe('comparison', function (): void {
    it('compares', function (): void {
        $five = Money::fromCents(500);
        $ten = Money::fromCents(1000);

        expect($five->lessThan($ten))->toBeTrue()
            ->and($ten->greaterThan($five))->toBeTrue()
            ->and($five->equals(Money::fromCents(500)))->toBeTrue()
            ->and($five->equals($ten))->toBeFalse()
            ->and($ten->greaterThanOrEqual($ten))->toBeTrue()
            ->and($five->compareTo($ten))->toBe(-1)
            ->and($ten->compareTo($five))->toBe(1)
            ->and($five->compareTo($five))->toBe(0)
            ->and($five->min($ten)->cents)->toBe(500)
            ->and($five->max($ten)->cents)->toBe(1000);
    });

    it('knows its sign', function (): void {
        expect(Money::zero()->isZero())->toBeTrue()
            ->and(Money::fromCents(1)->isPositive())->toBeTrue()
            ->and(Money::fromCents(-1)->isNegative())->toBeTrue()
            ->and(Money::zero()->isPositive())->toBeFalse()
            ->and(Money::zero()->isNegative())->toBeFalse();
    });
});
