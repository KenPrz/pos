<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** Revokes exactly the presented token — other sessions on other browsers survive. */
final class AdminLogout
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $user, string $tokenId): void
    {
        DB::transaction(function () use ($user, $tokenId): void {
            PersonalAccessToken::query()->whereKey($tokenId)->where('tokenable_id', $user->id)->delete();

            $this->audit->record('admin.logout', $user, $user->id);
        });
    }
}
