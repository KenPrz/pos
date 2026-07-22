<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Locations;

use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class ListLocationsRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::LOCATION_MANAGE);
    }

    public function rules(): array
    {
        return [];
    }
}
