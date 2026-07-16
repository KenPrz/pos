<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\ApplyDiscountInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class ApplyDiscountRequest extends FormRequest
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
            'discount_id' => ['required', 'uuid'],
            'order_line_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'max:200'],
            'if_match' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): ApplyDiscountInput
    {
        return new ApplyDiscountInput(
            orderId: (string) $this->route('order'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            discountId: $this->string('discount_id')->toString(),
            orderLineId: $this->input('order_line_id'),
            reason: $this->string('reason')->toString(),
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
