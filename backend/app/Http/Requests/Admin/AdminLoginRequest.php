<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Actions\Admin\AdminLoginInput;
use Illuminate\Foundation\Http\FormRequest;

final class AdminLoginRequest extends FormRequest
{
    /** Auth happens in the action itself — a wrong password is a domain refusal, not an authorization failure. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function toInput(): AdminLoginInput
    {
        return new AdminLoginInput(
            email: $this->string('email')->toString(),
            password: $this->string('password')->toString(),
            ip: $this->ip(),
        );
    }
}
