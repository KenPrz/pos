<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Register;

final readonly class StaffLoginInput
{
    public function __construct(
        public Register $register,
        public string $pin,
    ) {}
}
