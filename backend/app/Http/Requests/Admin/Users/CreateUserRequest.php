<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Users;

use App\Actions\Admin\Users\CreateUserInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CreateUserRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::USER_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            // The email-or-pin CHECK lives in the schema too; this is the 400-before-a-500
            // half of that invariant.
            'email' => ['nullable', 'email', 'max:255', 'required_without:pin'],
            'password' => ['nullable', 'string', 'min:10'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4,6}$/', 'required_without:email'],
            'is_admin' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*.location_id' => ['required', 'uuid', 'exists:locations,id'],
            'roles.*.role' => ['required', 'string', Rule::exists('role_templates', 'name')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*.location_id' => ['required', 'uuid', 'exists:locations,id'],
            'permissions.*.permission' => ['required', 'string', Rule::in(Permissions::all())],
        ];
    }

    /**
     * Case-insensitive uniqueness, matching the DB's `unique index ... (lower(email))`.
     * `Rule::unique()` alone compares the raw value, so a differently-cased duplicate
     * would sail through validation and only fail at the database with a 500.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $email = $this->input('email');

            if ($email !== null && DB::table('users')->whereRaw('lower(email) = ?', [Str::lower($email)])->exists()) {
                $validator->errors()->add('email', 'That email is already in use.');
            }
        });
    }

    public function toInput(): CreateUserInput
    {
        return new CreateUserInput(
            name: $this->string('name')->toString(),
            email: $this->input('email'),
            password: $this->input('password'),
            pin: $this->input('pin'),
            isAdmin: $this->boolean('is_admin', false),
            roles: $this->input('roles', []),
            permissions: $this->input('permissions', []),
            actorId: $this->user()->id,
        );
    }
}
