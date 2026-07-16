<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Money\Money;
use App\Models\Payment;
use LogicException;

/**
 * We never touch the money — a terminal captures the card, and this driver just
 * records the reference it hands back. That's why refundable is false: refunding here
 * would mean pretending to move money we were never in the middle of.
 */
final class ExternalCardDriver implements PaymentDriver
{
    public function code(): string
    {
        return 'external_card';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(refundable: false, async: false);
    }

    // We're a ledger for it, not a processor: the terminal already captured the card
    // before this call happens, so there is no tender to compute — only the reference
    // to keep, passed straight through from the intent.
    public function authorize(PaymentIntent $intent): PaymentResult
    {
        return new PaymentResult(status: 'captured', tender: null, reference: $intent->reference);
    }

    public function capture(Payment $payment): PaymentResult
    {
        return new PaymentResult(status: 'captured', tender: null, reference: null);
    }

    // Unreachable in practice: POST /refunds validates driver against `in:cash`, so no
    // request ever reaches a driver's refund() with external_card. A driver that
    // silently claimed refundability anyway would corrupt reconciliation — better to
    // fail loudly here than let capabilities() and refund() disagree.
    public function refund(Payment $payment, Money $amount): PaymentResult
    {
        throw new LogicException('external_card is not refundable — this call is unreachable.');
    }

    // A mis-keyed card payment is still just a payment, and voiding is same-shift
    // bookkeeping, not a call to the processor.
    public function void(Payment $payment): PaymentResult
    {
        return new PaymentResult(status: 'voided', tender: null, reference: null);
    }
}
