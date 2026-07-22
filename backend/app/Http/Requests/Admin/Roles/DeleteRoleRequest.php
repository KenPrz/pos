<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Roles;

use App\Actions\Admin\Roles\DeleteRoleInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class DeleteRoleRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::ROLE_MANAGE);
    }

    public function rules(): array
    {
        return [];
    }

    public function toInput(): DeleteRoleInput
    {
        return new DeleteRoleInput(
            roleTemplateId: (string) $this->route('role_template'),
            actorId: $this->user()->id,
        );
    }
}
