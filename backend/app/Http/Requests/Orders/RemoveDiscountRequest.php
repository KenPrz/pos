<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\RemoveDiscountInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class RemoveDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_DISCOUNT_APPLY);
    }

    protected function prepareForValidation(): void
    {
        // Presence and well-formedness only; the compare happens inside the
        // transaction, after the lock (docs/04-backend-conventions.md).
        $this->merge(['if_match' => $this->header('If-Match')]);
    }

    public function rules(): array
    {
        return [
            'if_match' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): RemoveDiscountInput
    {
        return new RemoveDiscountInput(
            orderId: (string) $this->route('order'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            orderDiscountId: (string) $this->route('discount'),
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
