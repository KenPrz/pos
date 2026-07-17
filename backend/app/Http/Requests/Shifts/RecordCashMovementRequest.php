<?php

declare(strict_types=1);

namespace App\Http\Requests\Shifts;

use App\Actions\Shifts\RecordCashMovementInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class RecordCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::SHIFT_CASH_MOVEMENT);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:payout,paid_in,drop'],
            // Sign lives in `kind`; a typo here cannot turn a payout into a paid-in.
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:200'],
        ];
    }

    public function toInput(): RecordCashMovementInput
    {
        return new RecordCashMovementInput(
            shiftId: (string) $this->route('shift'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            kind: $this->string('kind')->toString(),
            amountCents: $this->integer('amount_cents'),
            reason: $this->string('reason')->toString(),
            actorId: $this->user()->id,
        );
    }
}
