<?php

declare(strict_types=1);

namespace App\Http\Requests\Shifts;

use App\Actions\Shifts\CloseShiftInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::SHIFT_CLOSE);
    }

    protected function prepareForValidation(): void
    {
        // The middleware treats the key as optional; this endpoint requires it.
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'counted_cash_cents' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function toInput(): CloseShiftInput
    {
        return new CloseShiftInput(
            shiftId: (string) $this->route('shift'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            countedCashCents: $this->integer('counted_cash_cents'),
            note: $this->string('note')->toString() ?: null,
            actorId: $this->user()->id,
        );
    }
}
