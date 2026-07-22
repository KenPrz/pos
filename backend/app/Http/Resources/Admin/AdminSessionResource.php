<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Actions\Admin\AdminSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AdminSession */
final class AdminSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AdminSession $session */
        $session = $this->resource;

        return [
            'token' => $session->token,
            'user' => [
                'id' => $session->user->id,
                'name' => $session->user->name,
                'email' => $session->user->email,
                'is_admin' => $session->user->is_admin,
            ],
            'currency' => config('pos.currency'),
        ];
    }
}
