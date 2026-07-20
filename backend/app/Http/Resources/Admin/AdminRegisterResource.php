<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Register;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Register */
final class AdminRegisterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'name' => $this->name,
            'mode' => $this->mode,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'activation' => $this->activationState(),
        ];
    }

    /**
     * Presentation of the enrollment lifecycle. `has_device_token` is preloaded by
     * ListRegisters' withExists; the single-model paths (create/update responses) fall
     * back to an exists query.
     *
     * @return array{state: string, code_expires_at: \Illuminate\Support\Carbon|null}
     */
    private function activationState(): array
    {
        $hasDeviceToken = (bool) ($this->has_device_token ?? $this->tokens()->exists());

        if ($hasDeviceToken) {
            return ['state' => 'enrolled', 'code_expires_at' => null];
        }

        if ($this->activation_code_lookup !== null && $this->activation_code_redeemed_at === null) {
            $pending = $this->activation_code_expires_at?->isFuture() ?? false;

            return [
                'state' => $pending ? 'code_pending' : 'code_expired',
                'code_expires_at' => $pending ? $this->activation_code_expires_at : null,
            ];
        }

        return ['state' => 'not_enrolled', 'code_expires_at' => null];
    }
}
