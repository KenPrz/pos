<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateVariantInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateVariantRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('product_variants', 'sku')->whereNull('deleted_at')],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')->whereNull('deleted_at')],
            'price_cents' => ['required', 'integer', 'min:0'],
            'cost_cents' => ['nullable', 'integer', 'min:0'],
            'tax_rate_id' => ['nullable', 'uuid', 'exists:tax_rates,id'],
            'track_inventory' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): CreateVariantInput
    {
        return new CreateVariantInput(
            productId: $this->string('product_id')->toString(),
            name: $this->string('name')->toString(),
            sku: $this->string('sku')->toString(),
            barcode: $this->input('barcode'),
            priceCents: (int) $this->input('price_cents'),
            costCents: $this->has('cost_cents') ? (int) $this->input('cost_cents') : null,
            taxRateId: $this->input('tax_rate_id'),
            trackInventory: $this->boolean('track_inventory', true),
            actorId: $this->user()->id,
        );
    }
}
