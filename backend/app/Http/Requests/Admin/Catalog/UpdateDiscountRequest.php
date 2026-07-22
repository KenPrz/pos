<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Catalog;

use App\Actions\Admin\Catalog\UpdateDiscountInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Models\Discount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDiscountRequest extends FormRequest
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
            'kind' => ['sometimes', 'string', 'in:percent,fixed'],
            'percent_micros' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'scope' => ['sometimes', 'string', 'in:order,line'],
            'requires_supervisor' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * `sometimes` alone validates each field in isolation, which lets a lone
     * `amount_cents` patch pass while the row still carries the old percent_micros — the
     * DB CHECK would then 500. Cross-field soundness on a PATCH has to be checked
     * against the *merged* result (existing row + this request's changes), so we load
     * the row and simulate the final state. `input($field, $existing->$field)` does the
     * merge in one call: Arr::get returns null (not the default) when the key is present
     * with an explicit null, which is exactly how a client drops a value in the same
     * request that flips `kind`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $existing = Discount::query()->find($this->route('discount'));
            if ($existing === null) {
                return;
            }

            $kind = $this->input('kind', $existing->kind->value);
            $percent = $this->input('percent_micros', $existing->percent_micros);
            $amount = $this->input('amount_cents', $existing->amount_cents);

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

    public function toInput(): UpdateDiscountInput
    {
        return new UpdateDiscountInput(
            discountId: (string) $this->route('discount'),
            changes: $this->safe()->only([
                'name', 'kind', 'percent_micros', 'amount_cents', 'scope', 'requires_supervisor', 'is_active',
            ]),
            actorId: $this->user()->id,
        );
    }
}
