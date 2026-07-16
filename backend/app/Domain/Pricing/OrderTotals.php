<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money\Money;
use App\Domain\Money\Quantity;
use App\Domain\Money\TaxRate;
use App\Models\Order;
use App\Models\OrderDiscount;

/**
 * The one place order money is computed. Resolves discounts, recalculates every
 * non-voided line, then the order — per-line rounding then sum, so the receipt adds up
 * in front of a customer checking it with their phone. See docs/01-architecture.md
 * (rounding).
 */
final class OrderTotals
{
    public function __construct(
        private readonly DiscountResolver $discounts,
    ) {}

    public function recalculate(Order $order): void
    {
        // Same transaction as everything below: discount_cents must be settled before
        // the per-line pass reads it, since tax is computed on the discounted base.
        $this->discounts->resolve($order);

        $lines = $order->lines()->whereNull('voided_at')->get();

        $subtotal = Money::zero();
        $tax = Money::zero();

        foreach ($lines as $line) {
            $base = Money::fromCents($line->unit_price_cents)
                ->multipliedByQuantity(Quantity::fromString($line->qty))
                ->plus(Money::fromCents($line->modifiers_total_cents))
                ->minus(Money::fromCents($line->discount_cents));

            $rate = TaxRate::fromMicros($line->tax_rate_micros);
            $lineTax = $order->prices_include_tax ? $rate->taxOnGross($base) : $rate->taxOnNet($base);

            $line->forceFill([
                'line_total_cents' => $base->cents,
                'tax_cents' => $lineTax->cents,
            ])->save();

            $subtotal = $subtotal->plus($base);
            $tax = $tax->plus($lineTax);
        }

        // Inclusive prices already contain the tax; exclusive adds it at the till.
        $total = $order->prices_include_tax ? $subtotal : $subtotal->plus($tax);

        $discountTotal = (int) OrderDiscount::query()->where('order_id', $order->id)->sum('amount_cents');

        $order->forceFill([
            'subtotal_cents' => $subtotal->cents,
            'discount_cents' => $discountTotal,
            'tax_cents' => $tax->cents,
            'total_cents' => $total->cents,
        ])->save();
    }
}
