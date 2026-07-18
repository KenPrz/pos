<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;

final readonly class AdminSession
{
    public function __construct(
        public User $user,
        public string $token,
    ) {}
}
