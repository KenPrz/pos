<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

final readonly class RefundOrderInput
{
    /** @param list<RefundLineInput> $lines */
    public function __construct(
        public string $originalOrderId,
        public string $registerId,
        public string $driver,
        public string $reason,
        public array $lines,
        public string $actorId,
    ) {}
}
