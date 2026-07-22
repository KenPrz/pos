<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Roles;

use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class ListRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ROLE_MANAGE);
    }

    public function rules(): array
    {
        return [];
    }
}
