<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

final readonly class OpenShiftInput
{
    public function __construct(
        public string $registerId,
        public int $openingFloatCents,
        public string $actorId,
    ) {}
}
