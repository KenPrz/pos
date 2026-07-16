<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Money;
use App\Domain\Money\Tender;
use App\Models\Payment;

final class CashDriver implements PaymentDriver
{
    public function code(): string
    {
        return 'cash';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(refundable: true, async: false);
    }

    public function authorize(PaymentIntent $intent): PaymentResult
    {
        // Tender::cash throws InsufficientTender (422) when tendered < applied —
        // handing over less than you're applying is impossible, not a partial payment.
        $tender = $intent->tendered === null
            ? Tender::exact($intent->amount)
            : Tender::cash($intent->amount, $intent->tendered);

        return new PaymentResult(status: 'captured', tender: $tender, reference: null);
    }

    // Cash settles the moment it hits the drawer. A contained no-op here beats
    // reshaping the order flow the day an async driver arrives.
    public function capture(Payment $payment): PaymentResult
    {
        return new PaymentResult(status: 'captured', tender: null, reference: null);
    }

    public function refund(Payment $payment, Money $amount): PaymentResult
    {
        return new PaymentResult(status: 'captured', tender: null, reference: null);
    }

    public function void(Payment $payment): PaymentResult
    {
        return new PaymentResult(status: 'voided', tender: null, reference: null);
    }
}
