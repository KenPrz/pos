<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\CreatePaymentMethodGroupInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreatePaymentMethodGroupRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

    /** Codes are wire identifiers and report keys — normalized, never free-form casing. */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9_]+$/',
                // Unique PER LOCATION — the same code at another store is legal and
                // expected. The partial-scope unique index is the real gate; this turns
                // a 500 into a 422 with a field message.
                Rule::unique('payment_method_groups', 'code')
                    ->where(fn ($query) => $query->where('location_id', $this->input('location_id'))),
            ],
            'name' => ['required', 'string', 'max:200'],
            // The driver is the code seam, so the legal set is code, not data.
            'driver' => ['required', 'string', 'in:cash,external_card'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn () => $this->assertLocationPermitted(
            $this->string('location_id')->toString(),
            Permissions::PAYMENT_METHOD_MANAGE,
        ));
    }

    public function toInput(): CreatePaymentMethodGroupInput
    {
        return new CreatePaymentMethodGroupInput(
            locationId: $this->string('location_id')->toString(),
            code: $this->string('code')->toString(),
            name: $this->string('name')->toString(),
            driver: $this->string('driver')->toString(),
            sortOrder: $this->integer('sort_order', 0),
            isActive: $this->boolean('is_active', true),
            actorId: $this->user()->id,
        );
    }
}
