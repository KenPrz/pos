<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Users;

use App\Actions\Admin\Users\UpdateUserInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::USER_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'min:10'],
            'pin' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4,6}$/'],
            'is_admin' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*.location_id' => ['required', 'uuid', 'exists:locations,id'],
            'roles.*.role' => ['required', 'string', Rule::exists('role_templates', 'name')],
        ];
    }

    /** Case-insensitive uniqueness, excluding this user's own row. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $email = $this->input('email');

            if ($email === null || ! $this->filled('email')) {
                return;
            }

            $taken = DB::table('users')
                ->whereRaw('lower(email) = ?', [Str::lower($email)])
                ->where('id', '!=', $this->route('user'))
                ->exists();

            if ($taken) {
                $validator->errors()->add('email', 'That email is already in use.');
            }
        });
    }

    public function toInput(): UpdateUserInput
    {
        $changes = $this->safe()->only(['name', 'email', 'is_admin', 'is_active']);

        if ($this->filled('password')) {
            $changes['password_hash'] = $this->input('password');
        }

        return new UpdateUserInput(
            userId: (string) $this->route('user'),
            changes: $changes,
            pin: $this->input('pin'),
            roles: $this->has('roles') ? $this->input('roles') : null,
            actorId: $this->user()->id,
        );
    }
}
