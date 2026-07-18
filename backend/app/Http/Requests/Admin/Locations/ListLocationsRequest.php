<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Locations;

use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class ListLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::LOCATION_MANAGE);
    }

    public function rules(): array
    {
        return [];
    }
}
