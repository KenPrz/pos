<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * A tax rate, as integer millionths ("micros"). 8.875% is 88_750.
 *
 * Basis points would be the obvious choice and are wrong: NYC's combined 8.875% needs
 * sub-basis-point precision, and discovering that after launch means rewriting every
 * stored rate. See docs/02-data-model.md.
 */
final readonly class TaxRate
{
    /** 100% expressed in micros. */
    public const int ONE = 1_000_000;

    private const int MICROS_PER_PERCENT = 10_000;

    private const int PERCENT_DECIMALS = 4;

    private function __construct(
        public int $micros,
    ) {}

    public static function fromMicros(int $micros): self
    {
        if ($micros < 0) {
            throw new InvalidArgumentException('A tax rate cannot be negative.');
        }

        return new self($micros);
    }

    /** '8.875' -> 88_750. Up to four decimal places of a percent. */
    public static function fromPercent(string $percent): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', trim($percent), $m) !== 1) {
            throw new InvalidArgumentException(
                "Not a well-formed percentage: '{$percent}'. Expected digits with at most four decimal places."
            );
        }

        $whole = (int) $m[1];
        $fraction = (int) str_pad($m[2] ?? '0', self::PERCENT_DECIMALS, '0');

        return new self($whole * self::MICROS_PER_PERCENT + $fraction);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function isZero(): bool
    {
        return $this->micros === 0;
    }

    /**
     * Tax-exclusive pricing (US retail): the shelf price is net, tax is added at checkout.
     *
     *     tax = round(net × rate)
     */
    public function taxOnNet(Money $net): Money
    {
        return $net->fraction($this->micros, self::ONE);
    }

    /**
     * Tax-inclusive pricing (EU/UK/AU): the shelf price already contains the tax, so it
     * is *extracted* rather than added.
     *
     *     tax = round(gross × rate / (1 + rate))
     *
     * Both paths store tax_amount on the line, so reporting never needs to know which
     * mode produced it. See docs/01-architecture.md.
     */
    public function taxOnGross(Money $gross): Money
    {
        return $gross->fraction($this->micros, self::ONE + $this->micros);
    }

    /** The net portion of a tax-inclusive amount. Always exactly gross − tax. */
    public function netFromGross(Money $gross): Money
    {
        return $gross->minus($this->taxOnGross($gross));
    }

    public function equals(self $other): bool
    {
        return $this->micros === $other->micros;
    }

    /** '8.875' — for receipts and reports. */
    public function toPercentString(): string
    {
        $whole = intdiv($this->micros, self::MICROS_PER_PERCENT);
        $fraction = $this->micros % self::MICROS_PER_PERCENT;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return rtrim(sprintf('%d.%04d', $whole, $fraction), '0');
    }
}
