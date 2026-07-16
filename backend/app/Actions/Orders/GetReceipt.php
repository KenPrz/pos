<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;

/**
 * Loads exactly what a receipt renders. Every displayed value comes from snapshot
 * columns on order_lines/orders — the live catalog is never consulted, which is the
 * entire reason those columns exist. See docs/02-data-model.md.
 */
final class GetReceipt
{
    public function execute(string $orderId): Order
    {
        return Order::with([
            'lines' => fn ($q) => $q->whereNull('voided_at')->orderBy('position'),
            'payments' => fn ($q) => $q->where('status', 'captured')->orderBy('created_at'),
            'location',
            'openedBy',
        ])->findOrFail($orderId);
    }
}
