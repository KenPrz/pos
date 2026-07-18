<?php

declare(strict_types=1);

namespace App\Actions\Admin;

final readonly class AdminLoginInput
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $ip,
    ) {}
}
