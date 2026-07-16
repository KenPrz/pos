<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Actions\Stock\CountStockInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class CountStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::STOCK_COUNT);
    }

    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'uuid', 'exists:product_variants,id'],
            // Unlike qty/qty_delta, zero is a legal count — a wiped-out shelf is a real
            // state, so '0' and '0.000' are not excluded here.
            'counted_qty' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function toInput(): CountStockInput
    {
        return new CountStockInput(
            variantId: $this->string('variant_id')->toString(),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            countedQty: $this->string('counted_qty')->toString(),
            note: $this->input('note'),
            actorId: $this->user()->id,
        );
    }
}
