<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\UpdateLineQtyInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateLineQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_LINE_UPDATE);
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
            'qty' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'if_match' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): UpdateLineQtyInput
    {
        return new UpdateLineQtyInput(
            orderId: (string) $this->route('order'),
            lineId: (string) $this->route('line'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            qty: $this->string('qty')->toString(),
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
            actorMayVoidLines: $this->user()->can(Permissions::ORDER_LINE_VOID),
        );
    }
}
