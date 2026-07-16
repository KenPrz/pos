<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Actions\Payments\VoidPaymentInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class VoidPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::PAYMENT_VOID);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:200'],
        ];
    }

    public function toInput(): VoidPaymentInput
    {
        return new VoidPaymentInput(
            paymentId: (string) $this->route('payment'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            reason: $this->string('reason')->toString(),
            actorId: $this->user()->id,
        );
    }
}
