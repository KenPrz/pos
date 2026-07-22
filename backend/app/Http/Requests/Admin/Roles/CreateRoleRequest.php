<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Roles;

use App\Actions\Admin\Roles\CreateRoleInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ROLE_MANAGE);
    }

    /** Normalize before the unique check runs, or a differently-cased duplicate sails through. */
    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => strtolower(trim($this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:60',
                'regex:/^[a-z0-9][a-z0-9 _-]*$/i',
                Rule::unique('role_templates', 'name'),
            ],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ];
    }

    public function toInput(): CreateRoleInput
    {
        return new CreateRoleInput(
            name: $this->string('name')->toString(),
            permissions: $this->input('permissions', []),
            actorId: $this->user()->id,
        );
    }
}
