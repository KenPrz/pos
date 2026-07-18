<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateTaxRateInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'rate_micros' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): UpdateTaxRateInput
    {
        return new UpdateTaxRateInput(
            taxRateId: (string) $this->route('tax_rate'),
            changes: $this->safe()->only(['name', 'rate_micros', 'is_active']),
            actorId: $this->user()->id,
        );
    }
}
