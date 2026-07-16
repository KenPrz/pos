<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Register;

/**
 * Loads exactly what a receipt renders. Every displayed value comes from snapshot
 * columns on order_lines/orders — the live catalog is never consulted, which is the
 * entire reason those columns exist. See docs/02-data-model.md.
 */
final class GetReceipt
{
    public function execute(string $orderId, string $registerId): Order
    {
        // Another location's order is a 404, not a bypass — teams scope permission
        // checks, but record fetches must still be location-scoped by hand (docs/05-rbac.md).
        $locationId = Register::findOrFail($registerId)->location_id;

        return Order::where('location_id', $locationId)->with([
            'lines' => fn ($q) => $q->whereNull('voided_at'),
            'payments' => fn ($q) => $q->where('status', 'captured')->orderBy('created_at'),
            'location',
            'openedBy',
        ])->findOrFail($orderId);
    }
}
