<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Auth\EnrolledRegister;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EnrolledRegister */
final class EnrolledRegisterResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EnrolledRegister $enrolled */
        $enrolled = $this->resource;

        return [
            'register' => RegisterResource::make($enrolled->register),
            // Returned exactly once. Never retrievable again — only revocable.
            'device_token' => $enrolled->deviceToken,
        ];
    }
}
