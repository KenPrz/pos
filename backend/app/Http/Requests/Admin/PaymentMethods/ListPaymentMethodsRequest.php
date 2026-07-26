<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\ListPaymentMethodsInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class ListPaymentMethodsRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

    public function rules(): array
    {
        return ['location_id' => ['required', 'uuid', 'exists:locations,id']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn () => $this->assertLocationPermitted(
            $this->string('location_id')->toString(),
            Permissions::PAYMENT_METHOD_MANAGE,
        ));
    }

    public function toInput(): ListPaymentMethodsInput
    {
        return new ListPaymentMethodsInput(
            locationId: $this->string('location_id')->toString(),
        );
    }
}
