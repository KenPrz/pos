<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Money;

final readonly class PaymentIntent
{
    public function __construct(
        public Money $amount,
        public ?Money $tendered,     // cash only
        public ?string $reference,   // external terminal reference
    ) {}
}
