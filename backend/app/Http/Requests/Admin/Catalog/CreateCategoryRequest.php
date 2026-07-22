<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateCategoryInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class CreateCategoryRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'parent_id' => ['nullable', 'uuid', 'exists:categories,id'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }

    public function toInput(): CreateCategoryInput
    {
        return new CreateCategoryInput(
            name: $this->string('name')->toString(),
            parentId: $this->input('parent_id'),
            sortOrder: (int) $this->input('sort_order', 0),
            actorId: $this->user()->id,
        );
    }
}
