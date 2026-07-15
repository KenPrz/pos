<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Actions\Auth\StaffLoginInput;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\Register;
use Illuminate\Foundation\Http\FormRequest;

final class StaffLoginRequest extends FormRequest
{
    /** The device token is the authorization here; the PIN decides *who*, not *whether*. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // Digits only, and never `numeric`: '0123' must survive as a string.
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ];
    }

    public function toInput(): StaffLoginInput
    {
        /** @var Register $register */
        $register = $this->attributes->get(EnsureDeviceToken::REGISTER);

        return new StaffLoginInput(
            register: $register,
            pin: $this->string('pin')->toString(),
        );
    }
}
