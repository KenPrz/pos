<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Roles;

use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class ListPermissionsRequest extends FormRequest
{
    use AuthorizesBackOffice;

    /** The catalog also backs the user-management screen, so either manager can read it. */
    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::ROLE_MANAGE) || $this->allowsBackOffice(Permissions::USER_MANAGE);
    }

    public function rules(): array
    {
        return [];
    }
}
