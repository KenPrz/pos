<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateTaxRateInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use Illuminate\Foundation\Http\FormRequest;

final class CreateTaxRateRequest extends FormRequest
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
            // Millionths, never floats. 0..1_000_000 is 0%..100% — see App\Domain\Money\TaxRate.
            'rate_micros' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): CreateTaxRateInput
    {
        return new CreateTaxRateInput(
            name: $this->string('name')->toString(),
            rateMicros: (int) $this->input('rate_micros'),
            isActive: $this->boolean('is_active', true),
            actorId: $this->user()->id,
        );
    }
}
