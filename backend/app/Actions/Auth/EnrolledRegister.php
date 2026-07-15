<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Register;

/**
 * The register plus its plaintext token — returned exactly once, at enrolment, and never
 * retrievable again.
 */
final readonly class EnrolledRegister
{
    public function __construct(
        public Register $register,
        public string $deviceToken,
    ) {}
}
