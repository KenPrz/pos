<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Rbac\AdminAccess;
use App\Exceptions\Domain\InvalidCredentials;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Back-office sign-in. One failure mode on purpose: wrong email, wrong password,
 * deactivated, and holding no admin-tier permission all answer identically, because a
 * distinguishable refusal is a user-enumeration oracle. `is_admin` always passes (it
 * bypasses the gate entirely); anyone else needs at least one admin-tier permission
 * grant, resolved by `AdminAccess` — a location-less surface has no team context to
 * check a role against, so "holds it anywhere" is the question, not "holds it here".
 */
final class AdminLogin
{
    public function __construct(private readonly AuditLogger $audit, private readonly AdminAccess $access) {}

    public function execute(AdminLoginInput $in): AdminSession
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($in->email)])->first();

        if ($user === null
            || ! $user->is_active
            || ! $this->access->holdsAnyAdminSection($user)
            || $user->password_hash === null
            || ! Hash::check($in->password, $user->password_hash)) {
            throw new InvalidCredentials;
        }

        return DB::transaction(function () use ($user, $in): AdminSession {
            // EnsureBackOffice checks this ability (`currentAccessToken()?->can('admin')`)
            // as well as the permission check above — a register staff-session token
            // authenticates as this same User row, so the permission check alone would
            // let a staff token belonging to a supervisor who already holds
            // report.sales.view walk straight into the back office. Minting `['admin']`
            // here, rather than leaving it at Sanctum's unspecified-token wildcard
            // `['*']`, is what lets that second check actually distinguish an
            // admin-login token from a device or staff one. See EnsureBackOffice.
            $token = $user->createToken("admin:{$user->id}", ['admin']);

            $this->audit->record('admin.login', $user, $user->id, ip: $in->ip);

            return new AdminSession($user, $token->plainTextToken);
        });
    }
}
