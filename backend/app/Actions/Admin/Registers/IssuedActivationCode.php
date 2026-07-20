<?php

declare(strict_types=1);

namespace App\Actions\Admin\Registers;

use App\Models\Register;

/**
 * The register plus its plaintext activation code — shown to the admin exactly once,
 * never persisted, never retrievable again. Same convention as EnrolledRegister.
 */
final readonly class IssuedActivationCode
{
    public function __construct(
        public Register $register,
        public string $activationCode,
    ) {}
}
