<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Roles;

use App\Actions\Admin\Roles\UpdateRoleInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRoleRequest extends FormRequest
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
                'sometimes', 'string', 'max:60',
                'regex:/^[a-z0-9][a-z0-9 _-]*$/i',
                Rule::unique('role_templates', 'name')->ignore($this->route('role_template')),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ];
    }

    public function toInput(): UpdateRoleInput
    {
        return new UpdateRoleInput(
            roleTemplateId: (string) $this->route('role_template'),
            name: $this->filled('name') ? $this->string('name')->toString() : null,
            permissions: $this->has('permissions') ? $this->input('permissions') : null,
            actorId: $this->user()->id,
        );
    }
}
