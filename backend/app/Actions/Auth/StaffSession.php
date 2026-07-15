<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;

final readonly class StaffSession
{
    public function __construct(
        public User $user,
        public string $token,
        public Carbon $expiresAt,
    ) {}
}
