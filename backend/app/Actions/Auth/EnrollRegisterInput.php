<?php

declare(strict_types=1);

namespace App\Actions\Auth;

final readonly class EnrollRegisterInput
{
    public function __construct(
        public string $locationId,
        public string $name,
        public string $actorId,
    ) {}
}
