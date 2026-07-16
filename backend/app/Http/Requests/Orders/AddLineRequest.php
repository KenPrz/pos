<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Actions\Orders\AddLineInput;
use App\Domain\Rbac\Permissions;
use Illuminate\Foundation\Http\FormRequest;

final class AddLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::ORDER_LINE_ADD);
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
            'variant_id' => ['required', 'uuid'],
            'qty' => ['required', 'string', 'regex:/^\d+(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'modifiers' => ['prohibited'],   // ponytail: modifiers are M5; loud beats silently ignored
            'if_match' => ['required', 'integer', 'min:0'],
        ];
    }

    public function toInput(): AddLineInput
    {
        return new AddLineInput(
            orderId: (string) $this->route('order'),
            variantId: $this->string('variant_id')->toString(),
            qty: $this->string('qty')->toString(),
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
