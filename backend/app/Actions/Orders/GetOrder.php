<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Register;

final class GetOrder
{
    public function execute(string $orderId, string $registerId): Order
    {
        // Another location's order is a 404, not a bypass — teams scope permission
        // checks, but record fetches must still be location-scoped by hand (docs/05-rbac.md).
        $locationId = Register::findOrFail($registerId)->location_id;

        return Order::whereKey($orderId)
            ->where('location_id', $locationId)
            ->with(['lines', 'discounts'])
            ->firstOrFail();
    }
}
