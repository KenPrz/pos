<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateProductInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class CreateProductRequest extends FormRequest
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
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
            // Mirrors the products_kind CHECK constraint — 2026_07_16_000400_create_catalog_tables.php.
            'kind' => ['sometimes', 'string', 'in:goods,service'],
        ];
    }

    public function toInput(): CreateProductInput
    {
        return new CreateProductInput(
            name: $this->string('name')->toString(),
            description: $this->input('description'),
            categoryId: $this->input('category_id'),
            kind: $this->string('kind', 'goods')->toString(),
            actorId: $this->user()->id,
        );
    }
}
