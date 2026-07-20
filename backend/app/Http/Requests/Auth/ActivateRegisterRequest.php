<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Actions\Auth\ActivateRegisterInput;
use Illuminate\Foundation\Http\FormRequest;

final class ActivateRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The activation code is the authorization; the action decides if it's valid.
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'activation_code' => ['required', 'string', 'max:20'],
        ];
    }

    public function toInput(): ActivateRegisterInput
    {
        return new ActivateRegisterInput(
            activationCode: $this->string('activation_code')->toString(),
            ip: $this->ip(),
        );
    }
}
