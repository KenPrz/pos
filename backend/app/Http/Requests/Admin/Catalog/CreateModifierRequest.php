<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateModifierInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class CreateModifierRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'uuid', 'exists:modifier_groups,id'],
            'name' => ['required', 'string', 'max:200'],
            // Signed on purpose — a discount modifier ("no cheese, -50c") is real, and the
            // sign is the meaning. No min/max clamp here.
            'price_delta_cents' => ['required', 'integer'],
            'position' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): CreateModifierInput
    {
        return new CreateModifierInput(
            groupId: $this->string('group_id')->toString(),
            name: $this->string('name')->toString(),
            priceDeltaCents: (int) $this->input('price_delta_cents'),
            position: (int) $this->input('position', 0),
            isActive: $this->boolean('is_active', true),
            actorId: $this->user()->id,
        );
    }
}
