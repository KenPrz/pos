<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

final readonly class ApproveVarianceInput
{
    public function __construct(
        public string $shiftId,
        public string $registerId,
        public string $actorId,
    ) {}
}
