<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Registers;

use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class ListRegistersRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::REGISTER_ENROLL);
    }

    public function rules(): array
    {
        return [];
    }
}
