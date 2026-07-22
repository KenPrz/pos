<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class GetSettingsRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [];
    }
}
