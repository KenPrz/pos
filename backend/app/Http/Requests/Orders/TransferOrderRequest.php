<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\TransferOrderInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class TransferOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_TRANSFER);
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
            'register_id' => ['required', 'uuid'],
            'if_match' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): TransferOrderInput
    {
        return new TransferOrderInput(
            orderId: (string) $this->route('order'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            targetRegisterId: $this->string('register_id')->toString(),
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
