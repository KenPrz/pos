<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

final readonly class CloseShiftInput
{
    public function __construct(
        public string $shiftId,
        public string $registerId,
        public int $countedCashCents,
        public ?string $note,
        public string $actorId,
    ) {}
}
