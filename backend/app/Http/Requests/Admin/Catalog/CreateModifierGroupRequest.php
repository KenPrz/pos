<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateModifierGroupInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class CreateModifierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'min_select' => ['sometimes', 'integer', 'min:0'],
            'max_select' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Mirrors the modifier_groups_select_range CHECK — 2026_07_16_000400_create_catalog_tables.php.
     * Refused here with 400 rather than left to the DB's 500.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = (int) $this->input('min_select', 0);
            $max = $this->input('max_select');

            if ($max !== null && (int) $max < $min) {
                $validator->errors()->add('max_select', 'max_select must be >= min_select.');
            }
        });
    }

    public function toInput(): CreateModifierGroupInput
    {
        // input() returns null for both "absent" and "present but explicitly null" —
        // both mean "no max" here, so no separate has() check is needed. Cast only the
        // non-null case: the `integer` rule above accepts numeric strings ("2"), and a
        // string would otherwise hit the readonly ?int constructor param under
        // strict_types and throw instead of validating cleanly.
        $maxSelect = $this->input('max_select');

        return new CreateModifierGroupInput(
            name: $this->string('name')->toString(),
            minSelect: (int) $this->input('min_select', 0),
            maxSelect: $maxSelect !== null ? (int) $maxSelect : null,
            actorId: $this->user()->id,
        );
    }
}
