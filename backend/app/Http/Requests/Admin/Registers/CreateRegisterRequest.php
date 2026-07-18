<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Registers;

use App\Actions\Admin\Registers\CreateRegisterInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::REGISTER_ENROLL);
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:200',
                Rule::unique('registers', 'name')->where('location_id', $this->input('location_id'))],
            'mode' => ['sometimes', 'string', 'in:retail,food'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): CreateRegisterInput
    {
        return new CreateRegisterInput(
            locationId: $this->string('location_id')->toString(),
            name: $this->string('name')->toString(),
            mode: $this->string('mode', 'retail')->toString(),
            isActive: $this->boolean('is_active', true),
            actorId: $this->user()->id,
        );
    }
}
