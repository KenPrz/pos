<?php

declare(strict_types=1);

namespace App\Http\Requests\Shifts;

use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class OpenShiftRegistersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // No dedicated permission — any staff session at the register can see which
        // sibling registers are open. The `staff` middleware group is the actual gate:
        // a device token with no PIN entered never reaches this request at all.
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function registerId(): string
    {
        return $this->attributes->get(EnsureDeviceToken::REGISTER)->id;
    }
}
