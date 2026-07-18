<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\SetProductModifierGroupsInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class SetProductModifierGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'group_ids' => ['present', 'array'],
            'group_ids.*' => ['uuid', 'exists:modifier_groups,id'],
        ];
    }

    public function toInput(): SetProductModifierGroupsInput
    {
        return new SetProductModifierGroupsInput(
            productId: (string) $this->route('product'),
            groupIds: $this->input('group_ids', []),
            actorId: $this->user()->id,
        );
    }
}
