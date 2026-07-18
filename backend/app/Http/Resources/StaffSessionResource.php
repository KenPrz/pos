<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Auth\StaffSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StaffSession */
final class StaffSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StaffSession $session */
        $session = $this->resource;

        return [
            'staff_token' => $session->token,
            'expires_at' => $session->expiresAt->toIso8601String(),
            'user' => [
                'id' => $session->user->id,
                'name' => $session->user->name,
                'is_admin' => $session->user->is_admin,
                // What this person may do *at this register's location*. The register
                // renders from this rather than guessing from a role name.
                'permissions' => $session->user->getAllPermissions()->pluck('name')->values(),
            ],
            'register' => [
                'id' => $session->register->id,
                'name' => $session->register->name,
                'mode' => $session->register->mode,
            ],
        ];
    }
}
