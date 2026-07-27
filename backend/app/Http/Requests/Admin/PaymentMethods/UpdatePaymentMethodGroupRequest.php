<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\PaymentMethods;

use App\Actions\Admin\PaymentMethods\UpdatePaymentMethodGroupInput;
use App\Domain\Rbac\Permissions;
use App\Http\Requests\Concerns\AuthorizesBackOffice;
use App\Http\Requests\Concerns\ScopesToPermittedLocation;
use App\Models\PaymentMethodGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePaymentMethodGroupRequest extends FormRequest
{
    use AuthorizesBackOffice, ScopesToPermittedLocation;

    public function authorize(): bool
    {
        return $this->allowsBackOffice(Permissions::PAYMENT_METHOD_MANAGE);
    }

    /**
     * `code`, `driver` and `location_id` are absent from the rules on purpose, so
     * `safe()->only()` drops them: they are immutable after create. A client that sends
     * one is ignored rather than 422'd — the same shape every other admin PATCH has,
     * which applies only the keys it recognizes.
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
            PaymentMethodGroup::query()->whereKey((string) $this->route('group'))->value('location_id'),
            Permissions::PAYMENT_METHOD_MANAGE,
        ));
    }

    public function toInput(): UpdatePaymentMethodGroupInput
    {
        return new UpdatePaymentMethodGroupInput(
            groupId: (string) $this->route('group'),
            changes: $this->safe()->only(['name', 'sort_order', 'is_active']),
            actorId: $this->user()->id,
        );
    }
}
