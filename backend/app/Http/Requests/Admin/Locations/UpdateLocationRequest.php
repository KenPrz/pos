<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Locations;

use App\Actions\Admin\Locations\UpdateLocationInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::LOCATION_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('locations', 'code')->ignore($this->route('location'))],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'prices_include_tax' => ['sometimes', 'boolean'],
            'receipt_header' => ['sometimes', 'nullable', 'string'],
            'receipt_footer' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): UpdateLocationInput
    {
        return new UpdateLocationInput(
            locationId: (string) $this->route('location'),
            changes: $this->safe()->only([
                'name', 'code', 'timezone', 'prices_include_tax',
                'receipt_header', 'receipt_footer', 'is_active',
            ]),
            actorId: $this->user()->id,
        );
    }
}
