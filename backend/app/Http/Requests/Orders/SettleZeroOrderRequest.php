<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\SettleZeroOrderInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Settling a zero order is taking a tender of nothing — the same capability as taking
 * a payment, so the same permission gates it. The discount that made it zero was
 * already supervisor-gated at apply time.
 */
final class SettleZeroOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::PAYMENT_TAKE);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['if_match' => $this->header('If-Match')]);
    }

    public function rules(): array
    {
        return [
            'if_match' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): SettleZeroOrderInput
    {
        return new SettleZeroOrderInput(
            orderId: (string) $this->route('order'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
