<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Exceptions\Domain\InsufficientTender;
use InvalidArgumentException;

/**
 * One act of paying: what was applied to the order, what was physically handed over, and
 * what comes back. Mirrors amount_cents / tendered_cents / change_cents on the `payments`
 * table in docs/02-data-model.md.
 *
 * `applied` and `tendered` are distinct fields for a reason worth stating: on a $50 bill
 * a customer handing over $60 has not overpaid by $10 — they have tendered $60 against an
 * applied $50, and $10 is change. Collapsing the two is how a POS ends up recording a $60
 * payment and a $10 phantom refund.
 *
 * Change is computed here, in integers, and the register displays what it is told. The
 * client never does the subtraction.
 */
final readonly class Tender
{
    private function __construct(
        public Money $applied,
        public Money $tendered,
        public Money $change,
    ) {}

    public static function cash(Money $applied, Money $tendered): self
    {
        self::assertApplicable($applied);

        if ($tendered->lessThan($applied)) {
            throw new InsufficientTender($applied, $tendered);
        }

        return new self($applied, $tendered, $tendered->minus($applied));
    }

    /**
     * A tender with no change: cards, and anything settled for the exact amount.
     */
    public static function exact(Money $applied): self
    {
        self::assertApplicable($applied);

        return new self($applied, $applied, Money::zero());
    }

    public function givesChange(): bool
    {
        return $this->change->isPositive();
    }

    private static function assertApplicable(Money $applied): void
    {
        if (! $applied->isPositive()) {
            throw new InvalidArgumentException('A tender must apply a positive amount.');
        }
    }
}
