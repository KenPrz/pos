<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Actions\Stock\ReceiveStockInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class ReceiveStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::STOCK_RECEIVE);
    }

    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'uuid', 'exists:product_variants,id'],
            'qty' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function toInput(): ReceiveStockInput
    {
        return new ReceiveStockInput(
            variantId: $this->string('variant_id')->toString(),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            qty: $this->string('qty')->toString(),
            note: $this->input('note'),
            actorId: $this->user()->id,
        );
    }
}
