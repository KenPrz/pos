<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\SetTableRefInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class SetTableRefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_OPEN);
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
            'table_ref' => ['nullable', 'string', 'max:20'],
            'if_match' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): SetTableRefInput
    {
        return new SetTableRefInput(
            orderId: (string) $this->route('order'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            tableRef: $this->input('table_ref'),   // ->string() would coerce null to '' — table_ref must stay nullable
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
