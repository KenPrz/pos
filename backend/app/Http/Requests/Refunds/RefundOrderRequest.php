<?php

declare(strict_types=1);

namespace App\Http\Requests\Refunds;

use App\Actions\Refunds\RefundLineInput;
use App\Actions\Refunds\RefundOrderInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::REFUND_CREATE);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'original_order_id' => ['required', 'uuid'],
            // Refundability is enforced in the action from Capabilities::refundable, not
            // by an `in:` list here — the legal set is per-location data, and the rule
            // belongs where the capability is declared. 422 refund_method_not_refundable.
            'payment_method_code' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.original_order_line_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'lines.*.restock' => ['required', 'boolean'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function toInput(): RefundOrderInput
    {
        return new RefundOrderInput(
            originalOrderId: $this->string('original_order_id')->toString(),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            paymentMethodCode: $this->string('payment_method_code')->toString(),
            reason: $this->string('reason')->toString(),
            lines: array_map(
                static fn (array $line): RefundLineInput => new RefundLineInput(
                    originalOrderLineId: (string) $line['original_order_line_id'],
                    qty: (string) $line['qty'],
                    restock: (bool) $line['restock'],
                ),
                $this->input('lines'),
            ),
            actorId: $this->user()->id,
        );
    }
}
