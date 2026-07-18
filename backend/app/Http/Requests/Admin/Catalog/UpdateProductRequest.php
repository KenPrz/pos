<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateProductInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category_id' => ['sometimes', 'nullable', 'uuid', 'exists:categories,id'],
            'kind' => ['sometimes', 'string', 'in:goods,service'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): UpdateProductInput
    {
        return new UpdateProductInput(
            productId: (string) $this->route('product'),
            changes: $this->safe()->only(['name', 'description', 'category_id', 'kind', 'is_active']),
            actorId: $this->user()->id,
        );
    }
}
