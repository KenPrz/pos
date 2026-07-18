<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateModifierGroupInput;
use App\Domain\Rbac\Permissions;
use App\Models\ModifierGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateModifierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'min_select' => ['sometimes', 'integer', 'min:0'],
            'max_select' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * `sometimes` alone would let a lone min_select bump pass request-level validation
     * while inverting against the max_select already on the row. Rules run per-field, so
     * cross-field soundness on a PATCH has to be checked against the *merged* result —
     * the existing row plus this request's changes — not the request body in isolation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $existing = ModifierGroup::query()->find($this->route('modifier_group'));
            if ($existing === null) {
                return;
            }

            $min = $this->has('min_select') ? (int) $this->input('min_select') : $existing->min_select;
            $max = $this->has('max_select') ? $this->input('max_select') : $existing->max_select;

            if ($max !== null && (int) $max < $min) {
                $validator->errors()->add('max_select', 'max_select must be >= min_select.');
            }
        });
    }

    public function toInput(): UpdateModifierGroupInput
    {
        return new UpdateModifierGroupInput(
            modifierGroupId: (string) $this->route('modifier_group'),
            changes: $this->safe()->only(['name', 'min_select', 'max_select']),
            actorId: $this->user()->id,
        );
    }
}
