<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\OrderVersionConflict;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Register;

/**
 * The preamble every order mutation shares: location-scope the fetch (another
 * location's order is a 404, not a bypass — docs/05-rbac.md), lock the row, refuse
 * a non-open order, refuse a stale client. Call only inside DB::transaction.
 */
final class OpenOrderLock
{
    public function acquire(string $orderId, string $registerId, int $expectedVersion): Order
    {
        $locationId = Register::findOrFail($registerId)->location_id;

        $order = Order::whereKey($orderId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->status !== OrderStatus::Open) {
            throw new OrderClosed($order->id, $order->status->value);
        }
        if ($order->version !== $expectedVersion) {
            throw new OrderVersionConflict($order->id, $expectedVersion, $order->version);
        }

        return $order;
    }
}
