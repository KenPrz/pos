<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\UpdatePaymentMethodInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use App\Models\PaymentMethod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePaymentMethodRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

    /**
     * `code`, `group_id` and `location_id` are absent on purpose so `safe()->only()`
     * drops them — they are immutable after create.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Scoped against the ROW's location — an update names no location itself.
        $validator->after(fn () => $this->assertLocationPermitted(
            PaymentMethod::query()->whereKey((string) $this->route('method'))->value('location_id'),
            Permissions::PAYMENT_METHOD_MANAGE,
        ));
    }

    public function toInput(): UpdatePaymentMethodInput
    {
        return new UpdatePaymentMethodInput(
            methodId: (string) $this->route('method'),
            changes: $this->safe()->only(['name', 'sort_order', 'is_active']),
            actorId: $this->user()->id,
        );
    }
}
