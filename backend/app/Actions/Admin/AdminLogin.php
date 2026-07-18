<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\InvalidCredentials;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Back-office sign-in. One failure mode on purpose: wrong email, wrong password,
 * deactivated, and non-admin all answer identically, because a distinguishable
 * refusal is a user-enumeration oracle. Admin-only in v1 — supervisor access is a
 * named deferral in the spec (per-location team context doesn't belong on a
 * location-less surface).
 */
final class AdminLogin
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(AdminLoginInput $in): AdminSession
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($in->email)])->first();

        if ($user === null
            || ! $user->is_active
            || ! $user->is_admin
            || $user->password_hash === null
            || ! Hash::check($in->password, $user->password_hash)) {
            throw new InvalidCredentials;
        }

        return DB::transaction(function () use ($user, $in): AdminSession {
            // 'admin' here is a label for what this token is, not an enforcement
            // mechanism: createToken()'s ability list gates nothing on its own (Sanctum
            // grants any unspecified createToken() the wildcard ['*']), and EnsureAdmin
            // deliberately never checks it. See EnsureAdmin for why.
            $token = $user->createToken("admin:{$user->id}", ['admin']);

            $this->audit->record('admin.login', $user, $user->id, ip: $in->ip);

            return new AdminSession($user, $token->plainTextToken);
        });
    }
}
