<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Users;

use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::USER_MANAGE);
    }

    public function rules(): array
    {
        return [];
    }
}
