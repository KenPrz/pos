<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Actions\Payments\TakePaymentInput;
use App\Domain\Rbac\Permissions;
use App\Http\Middleware\EnsureDeviceToken;
use Illuminate\Foundation\Http\FormRequest;

final class TakePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permissions::PAYMENT_TAKE);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'if_match' => $this->header('If-Match'),
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            // The set of legal codes is per-location DATA, so it is not an `in:` list —
            // PaymentMethodResolver is the gate, and it answers 422 unknown/inactive.
            // `required` here is the structural gate: a request naming no tender at all is
            // malformed, and this codebase answers malformed with 400 validation_failed
            // (ApiErrorEnvelope), reserving 422 for structurally-fine-but-rejected.
            'payment_method_code' => ['required', 'string', 'max:32'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'tendered_cents' => ['nullable', 'integer', 'min:1'],   // absent = exact tender
            'reference' => ['nullable', 'string', 'max:100'],
            'if_match' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function toInput(): TakePaymentInput
    {
        return new TakePaymentInput(
            orderId: (string) $this->route('order'),
            registerId: $this->attributes->get(EnsureDeviceToken::REGISTER)->id,
            paymentMethodCode: $this->string('payment_method_code')->toString(),
            amountCents: $this->integer('amount_cents'),
            tenderedCents: $this->filled('tendered_cents') ? $this->integer('tendered_cents') : null,
            reference: $this->string('reference')->toString() ?: null,
            expectedVersion: (int) $this->header('If-Match'),
            actorId: $this->user()->id,
        );
    }
}
