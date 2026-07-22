<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateModifierInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateModifierRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            // Immutable on PATCH: which group a modifier belongs to is a create-time
            // decision, same reasoning as ProductVariant::product_id.
            'group_id' => ['prohibited'],
            'name' => ['sometimes', 'string', 'max:200'],
            'price_delta_cents' => ['sometimes', 'integer'],
            'position' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): UpdateModifierInput
    {
        return new UpdateModifierInput(
            modifierId: (string) $this->route('modifier'),
            changes: $this->safe()->only(['name', 'price_delta_cents', 'position', 'is_active']),
            actorId: $this->user()->id,
        );
    }
}
