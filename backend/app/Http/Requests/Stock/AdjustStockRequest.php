<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Actions\Stock\AdjustStockInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::STOCK_ADJUST);
    }

    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'uuid', 'exists:product_variants,id'],
            // Signed: either direction is a legal adjustment. A zero delta is not a
            // movement (stock_movements' own check constraint says so) and would hit it
            // as an unhandled 500 rather than a validation error, so it's excluded here.
            'qty_delta' => ['required', 'string', 'regex:/^-?\d{1,9}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000,-0,-0.0,-0.00,-0.000'],
            'reason' => ['required', 'string', 'in:adjustment,waste'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // StockLedger::adjust() enforces this too, but as a LogicException — a
            // caller mistake, not a client error. Catching it here means a positive
            // waste delta is a 400, not a 500.
            $delta = (string) $this->input('qty_delta');
            if ($this->input('reason') === 'waste' && ! str_starts_with($delta, '-')) {
                $validator->errors()->add('qty_delta', 'waste requires a negative qty_delta.');
            }
        });
    }

    public function toInput(): AdjustStockInput
    {
        return new AdjustStockInput(
            variantId: $this->string('variant_id')->toString(),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            qtyDelta: $this->string('qty_delta')->toString(),
            reason: $this->string('reason')->toString(),
            note: $this->input('note'),
            actorId: $this->user()->id,
        );
    }
}
