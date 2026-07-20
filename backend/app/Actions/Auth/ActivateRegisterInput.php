<?php

declare(strict_types=1);

namespace App\Actions\Auth;

final readonly class ActivateRegisterInput
{
    public function __construct(
        public string $activationCode,
        public ?string $ip,
    ) {}
}
