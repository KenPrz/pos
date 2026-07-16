<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\VoidLineInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class VoidLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_LINE_VOID);
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
            'reason' => ['required', 'string', 'max:200'],
            'if_match' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): VoidLineInput
    {
        return new VoidLineInput(
            orderId: (string) $this->route('order'),
            lineId: (string) $this->route('line'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            reason: $this->string('reason')->toString(),
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
