<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Registers;

use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class ListRegistersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::REGISTER_ENROLL);
    }

    public function rules(): array
    {
        return [];
    }
}
