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
            // external_card never passed through us — the money never touched this
            // system, so there is nothing here to refund. Validation, not a domain
            // exception: it is never a legal request, not a race that lost.
            'driver' => ['required', 'in:cash'],
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
            driver: $this->string('driver')->toString(),
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
