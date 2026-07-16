<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Tender;

final readonly class PaymentResult
{
    public function __construct(
        public string $status,       // 'captured' | 'pending' | 'failed'
        public ?Tender $tender,      // cash: applied/tendered/change; null otherwise
        public ?string $reference,
    ) {}
}
