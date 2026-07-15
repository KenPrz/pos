<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;
use Stringable;

/**
 * How many of something — held as an integer number of thousandths, mirroring the
 * numeric(12,3) columns in docs/02-data-model.md.
 *
 * Integer for the same reason Money is: you can sell 0.5 kg of cheese, and a float would
 * make 0.1 + 0.2 kg fail to be 0.3 kg. This is why quantities cross the wire as strings
 * (docs/03-api.md) — JS `number` is IEEE-754 and would corrupt them in transit.
 */
final readonly class Quantity implements Stringable
{
    /** Thousandths: numeric(12,3). */
    public const int SCALE = 1000;

    private const int DECIMALS = 3;

    private function __construct(
        public int $milli,
    ) {}

    public static function fromMilli(int $milli): self
    {
        return new self($milli);
    }

    public static function one(): self
    {
        return new self(self::SCALE);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse '0.500' / '2' / '1.25'. String arithmetic only — never a float cast.
     * More than three decimals is rejected rather than rounded: the column cannot hold
     * it, so accepting it would silently change the number.
     */
    public static function fromString(string $quantity): self
    {
        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,3}))?$/', trim($quantity), $m) !== 1) {
            throw new InvalidArgumentException(
                "Not a well-formed quantity: '{$quantity}'. Expected digits with at most three decimal places."
            );
        }

        $sign = $m[1] === '-' ? -1 : 1;
        $whole = (int) $m[2];
        $fraction = (int) str_pad($m[3] ?? '0', self::DECIMALS, '0');

        return new self($sign * ($whole * self::SCALE + $fraction));
    }

    public function plus(self $other): self
    {
        return new self($this->milli + $other->milli);
    }

    public function minus(self $other): self
    {
        return new self($this->milli - $other->milli);
    }

    public function negated(): self
    {
        return new self(-$this->milli);
    }

    public function isZero(): bool
    {
        return $this->milli === 0;
    }

    public function isPositive(): bool
    {
        return $this->milli > 0;
    }

    public function isNegative(): bool
    {
        return $this->milli < 0;
    }

    public function equals(self $other): bool
    {
        return $this->milli === $other->milli;
    }

    public function greaterThan(self $other): bool
    {
        return $this->milli > $other->milli;
    }

    public function lessThan(self $other): bool
    {
        return $this->milli < $other->milli;
    }

    /** Canonical wire/column form: always three decimals, e.g. '0.500'. */
    public function __toString(): string
    {
        $sign = $this->milli < 0 ? '-' : '';
        $abs = abs($this->milli);

        return sprintf('%s%d.%03d', $sign, intdiv($abs, self::SCALE), $abs % self::SCALE);
    }
}
