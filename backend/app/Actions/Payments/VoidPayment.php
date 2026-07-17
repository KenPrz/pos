<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\PaymentAlreadyVoided;
use App\Exceptions\Domain\PaymentShiftClosed;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a captured tender — a bounced card, a mis-rung cash count. The payment row
 * itself never changes what it says was taken (append-only); only its status moves to
 * `voided`, and the order's `paid_cents` drops to match. If that payment was the one
 * that closed the order, undoing it must reopen the order too — a closed order sitting
 * on money it no longer has would be a ledger that disagrees with itself.
 *
 * Blocked once the payment's shift has closed: the drawer already reconciled that cash
 * into `expected_cash_cents` and a recorded variance. Voiding after that would change
 * what "expected" meant after the count already happened — before shift close only.
 */
final class VoidPayment
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(VoidPaymentInput $in): Payment
    {
        return DB::transaction(function () use ($in): Payment {
            // Another location's payment is a 404, not a bypass — teams scope permission
            // checks, but record fetches must still be location-scoped by hand (docs/05-rbac.md).
            $locationId = Register::findOrFail($in->registerId)->location_id;

            $payment = Payment::query()
                ->whereKey($in->paymentId)
                ->whereHas('order', fn ($q) => $q->where('location_id', $locationId))
                ->firstOrFail();

            // paid_cents is what we're mutating, so the order is what we lock. Re-fetch
            // the payment through it once the lock is held — the copy above is only
            // good enough to find which order to lock, not to judge its own status.
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = $order->payments()->whereKey($in->paymentId)->firstOrFail();

            if ($payment->status !== 'captured') {
                throw new PaymentAlreadyVoided($payment->id);
            }

            $shift = Shift::find($payment->shift_id);
            if ($shift !== null && $shift->closed_at !== null) {
                throw new PaymentShiftClosed($payment->id, $shift->id);
            }

            $payment->forceFill(['status' => 'voided'])->save();

            $order->paid_cents -= $payment->amount_cents;
            if ($order->status === OrderStatus::Closed) {
                // The natural ledger consequence: an order can't stay "paid in full"
                // once the payment that made it so no longer counts.
                $order->status = OrderStatus::Open;
                $order->closed_at = null;
                $order->closed_by = null;
            }
            $order->version += 1;
            $order->save();

            $this->audit->record('payment.void', $payment, $in->actorId, [
                'reason' => $in->reason,
                'amount_cents' => $payment->amount_cents,
            ], registerId: $in->registerId);

            return $payment->setRelation('order', $order->fresh(['lines', 'discounts']));
        });
    }
}
