<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Domain\Audit\AuditLogger;
use App\Domain\Money\Money;
use App\Domain\Payments\DriverRegistry;
use App\Domain\Payments\PaymentIntent;
use App\Exceptions\Domain\NoOpenShift;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Exceptions\Domain\PaymentExceedsBalance;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Applies a tender. The order closes automatically when captured payments reach
 * total_cents — a manual close endpoint would be a second, disagreeing definition of
 * "paid in full". The payment's shift is the register's CURRENT shift, which is what
 * makes drawer variance computable when a tab spans a shift boundary.
 */
final class TakePayment
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(TakePaymentInput $in): Payment
    {
        return DB::transaction(function () use ($in): Payment {
            $order = Order::whereKey($in->orderId)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Open) {
                throw new OrderClosed($order->id, $order->status->value);
            }
            if ($order->version !== $in->expectedVersion) {
                throw new OrderVersionConflict($order->id, $in->expectedVersion, $order->version);
            }

            $shift = Shift::where('register_id', $in->registerId)->whereNull('closed_at')->first()
                ?? throw new NoOpenShift($in->registerId);

            $balance = $order->total_cents - $order->paid_cents;
            if ($in->amountCents > $balance) {
                // For cash the overage is change, not payment — the client should have
                // sent amount = balance and tendered = what was handed over.
                throw new PaymentExceedsBalance($order->id, $in->amountCents, $balance);
            }

            $result = $this->drivers->driver($in->driver)->authorize(new PaymentIntent(
                amount: Money::fromCents($in->amountCents),
                tendered: $in->tenderedCents === null ? null : Money::fromCents($in->tenderedCents),
                reference: $in->reference,
            ));

            $payment = Payment::create([
                'order_id' => $order->id,
                'shift_id' => $shift->id,
                'driver' => $in->driver,
                'status' => $result->status,
                'amount_cents' => $in->amountCents,
                'tendered_cents' => $result->tender?->tendered->cents,
                'change_cents' => $result->tender?->change->cents,
                'reference' => $result->reference ?? $in->reference,
                'driver_payload' => null,
                'user_id' => $in->actorId,
                'created_at' => now(),
                'captured_at' => $result->status === 'captured' ? now() : null,
            ]);

            if ($result->status === 'captured') {
                $order->paid_cents += $in->amountCents;
            }
            if ($order->paid_cents === $order->total_cents && $order->total_cents > 0) {
                $order->status = OrderStatus::Closed;
                $order->closed_at = now();
                $order->closed_by = $in->actorId;
            }
            $order->version += 1;
            $order->save();

            $this->audit->record('payment.take', $payment, $in->actorId, [
                'order_id' => $order->id,
                'driver' => $in->driver,
                'amount_cents' => $in->amountCents,
            ], registerId: $in->registerId);

            return $payment->setRelation('order', $order->fresh(['lines']));
        });
    }
}
