<?php

declare(strict_types=1);

namespace App\Actions\Auth;

final readonly class SetStaffPinInput
{
    public function __construct(
        public string $userId,
        public string $pin,
        public string $actorId,
    ) {}
}
