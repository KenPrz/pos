<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

final readonly class RecordCashMovementInput
{
    public function __construct(
        public string $shiftId,
        public string $registerId,
        public string $kind,
        public int $amountCents,
        public string $reason,
        public string $actorId,
    ) {}
}
