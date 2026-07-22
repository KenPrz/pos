<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateVariantInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateVariantRequest extends FormRequest
{
    use AuthorizesBackOffice;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            // Immutable on PATCH: which product a variant belongs to is a create-time
            // decision — snapshots make repricing future-only automatically, but moving
            // a variant to another product has no such safety net.
            'product_id' => ['prohibited'],
            'name' => ['sometimes', 'string', 'max:200'],
            'sku' => ['sometimes', 'string', 'max:100', Rule::unique('product_variants', 'sku')
                ->ignore($this->route('variant'))->whereNull('deleted_at')],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')
                ->ignore($this->route('variant'))->whereNull('deleted_at')],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            'cost_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tax_rate_id' => ['sometimes', 'nullable', 'uuid', 'exists:tax_rates,id'],
            'track_inventory' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): UpdateVariantInput
    {
        return new UpdateVariantInput(
            variantId: (string) $this->route('variant'),
            changes: $this->safe()->only([
                'name', 'sku', 'barcode', 'price_cents', 'cost_cents',
                'tax_rate_id', 'track_inventory', 'is_active',
            ]),
            actorId: $this->user()->id,
        );
    }
}
