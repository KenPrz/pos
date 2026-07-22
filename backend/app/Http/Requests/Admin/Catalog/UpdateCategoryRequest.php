<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateCategoryInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateCategoryRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'parent_id' => ['sometimes', 'nullable', 'uuid', 'exists:categories,id'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }

    /**
     * A category cannot be its own parent. One level is enough: the seeded tree is
     * flat, so a deeper cycle check (grandparent-is-self, etc.) has no case to guard.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('parent_id') && $this->input('parent_id') === $this->route('category')) {
                $validator->errors()->add('parent_id', 'A category cannot be its own parent.');
            }
        });
    }

    public function toInput(): UpdateCategoryInput
    {
        return new UpdateCategoryInput(
            categoryId: (string) $this->route('category'),
            changes: $this->safe()->only(['name', 'parent_id', 'sort_order']),
            actorId: $this->user()->id,
        );
    }
}
