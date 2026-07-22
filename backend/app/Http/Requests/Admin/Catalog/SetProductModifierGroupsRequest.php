<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\SetProductModifierGroupsInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class SetProductModifierGroupsRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'group_ids' => ['present', 'array'],
            // 'distinct' turns a repeated id into a clear 400 rather than a silent sync()
            // collapse (sync() keys the pivot by group id, so [g1, g1] would quietly land
            // as one row at whichever position won the array-key collision).
            'group_ids.*' => ['distinct', 'uuid', 'exists:modifier_groups,id'],
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
