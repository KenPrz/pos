<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Audit\AuditLogger;
use Laravel\Sanctum\PersonalAccessToken;

/** End a staff session. The device stays enrolled. */
final class StaffLogout
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function execute(PersonalAccessToken $staffToken): void
    {
        $userId = (string) $staffToken->tokenable_id;

        $staffToken->delete();

        $this->audit->record('staff.logout', 'User', $userId);
    }
}
