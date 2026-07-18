<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\CreateDiscountInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class CreateDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::CATALOG_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            // Mirrors discounts_kind / discounts_scope — 2026_07_16_000800_create_discounts_tables.php.
            'kind' => ['required', 'string', 'in:percent,fixed'],
            'percent_micros' => ['nullable', 'integer', 'min:0'],
            'amount_cents' => ['nullable', 'integer', 'min:0'],
            'scope' => ['required', 'string', 'in:order,line'],
            'requires_supervisor' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Mirrors discounts_kind_matches_value exactly: percent requires percent_micros and
     * forbids amount_cents, fixed is the inverse. Refused here with 400 rather than left
     * to the DB CHECK's 500.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $kind = $this->input('kind');
            $percent = $this->input('percent_micros');
            $amount = $this->input('amount_cents');

            if ($kind === 'percent') {
                if ($percent === null) {
                    $validator->errors()->add('percent_micros', 'percent_micros is required for a percent discount.');
                }
                if ($amount !== null) {
                    $validator->errors()->add('amount_cents', 'amount_cents must be absent for a percent discount.');
                }
            } elseif ($kind === 'fixed') {
                if ($amount === null) {
                    $validator->errors()->add('amount_cents', 'amount_cents is required for a fixed discount.');
                }
                if ($percent !== null) {
                    $validator->errors()->add('percent_micros', 'percent_micros must be absent for a fixed discount.');
                }
            }
        });
    }

    public function toInput(): CreateDiscountInput
    {
        return new CreateDiscountInput(
            name: $this->string('name')->toString(),
            kind: $this->string('kind')->toString(),
            percentMicros: $this->input('percent_micros') !== null ? (int) $this->input('percent_micros') : null,
            amountCents: $this->input('amount_cents') !== null ? (int) $this->input('amount_cents') : null,
            scope: $this->string('scope')->toString(),
            requiresSupervisor: $this->boolean('requires_supervisor', true),
            isActive: $this->boolean('is_active', true),
            actorId: $this->user()->id,
        );
    }
}
