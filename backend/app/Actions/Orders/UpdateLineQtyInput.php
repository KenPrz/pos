<?php

declare(strict_types=1);

namespace App\Actions\Orders;

final readonly class UpdateLineQtyInput
{
    public function __construct(
        public string $orderId,
        public string $lineId,
        public string $registerId,
        public string $qty,               // numeric string; absolute target, not a delta
        public int $expectedVersion,
        public string $actorId,
        public bool $actorMayVoidLines,   // evaluated in the FormRequest; the action has no HTTP/user access
    ) {}
}
