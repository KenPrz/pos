<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Users;

use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class ListUsersRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::USER_MANAGE);
    }

    public function rules(): array
    {
        return [];
    }
}
