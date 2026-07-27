<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\CreatePaymentMethodInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreatePaymentMethodRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

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
            'group_id' => [
                'required', 'uuid',
                // The composite FK enforces this at the database; checking here turns a
                // 500 into a field message.
                Rule::exists('payment_method_groups', 'id')
                    ->where(fn ($query) => $query->where('location_id', $this->input('location_id'))),
            ],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9_]+$/',
                // Unique per LOCATION, across every group at it.
                Rule::unique('payment_methods', 'code')
                    ->where(fn ($query) => $query->where('location_id', $this->input('location_id'))),
            ],
            'name' => ['required', 'string', 'max:200'],
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

    public function toInput(): CreatePaymentMethodInput
    {
        return new CreatePaymentMethodInput(
            locationId: $this->string('location_id')->toString(),
            groupId: $this->string('group_id')->toString(),
            code: $this->string('code')->toString(),
            name: $this->string('name')->toString(),
            sortOrder: $this->integer('sort_order', 0),
            isActive: $this->boolean('is_active', true),
            actorId: $this->user()->id,
        );
    }
}
