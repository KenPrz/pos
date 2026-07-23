<?php

declare(strict_types=1);

namespace App\Domain\Day;

final readonly class DaySnapshot
{
    public function __construct(
        public int $grossSalesCents,
        public int $refundsCents,
        public int $netSalesCents,
        public int $taxCents,
        public int $expectedCashCents,
        public int $countedCashCents,
        public int $varianceCents,
        public int $shiftCount,
    ) {}
}
