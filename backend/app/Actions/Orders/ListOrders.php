<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\Register;
use Illuminate\Database\Eloquent\Collection;

/**
 * A targeted lookup (receipt number for refunds, open orders for recovery) — not the
 * M5 floor view, which owns browsing and pagination.
 */
final class ListOrders
{
    /** @return Collection<int, Order> */
    public function execute(string $registerId, ?string $number, ?string $status): Collection
    {
        $locationId = Register::findOrFail($registerId)->location_id;

        return Order::where('location_id', $locationId)
            ->when($number !== null, fn ($q) => $q->where('number', $number))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->with(['lines', 'discounts'])
            ->orderByDesc('opened_at')
            ->limit(20)
            ->get();
    }
}
