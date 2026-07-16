<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Money;
use App\Models\Payment;

/**
 * The payment seam from docs/01-architecture.md. authorize/capture even though cash
 * doesn't need two steps — a driver that can't say "pending, waiting on the customer
 * to tap" forces every caller to be rewritten the day a real reader arrives.
 */
interface PaymentDriver
{
    public function code(): string;

    public function capabilities(): Capabilities;

    /** Begin a tender. May complete immediately (cash) or return pending (terminal). */
    public function authorize(PaymentIntent $intent): PaymentResult;

    /** Settle a prior authorization. Cash is a no-op; card processors are not. */
    public function capture(Payment $payment): PaymentResult;

    public function refund(Payment $payment, Money $amount): PaymentResult;

    public function void(Payment $payment): PaymentResult;
}
