<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OrderNumbers;
use App\Exceptions\Domain\NoOpenShift;
use App\Models\Order;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * Opens the lifecycle both retail and food service travel. Retail calls this
 * implicitly on first scan; food service names a table. Same row either way.
 * prices_include_tax is snapshotted here so an admin flipping the setting mid-shift
 * can't change the arithmetic of orders already in flight.
 */
final class OpenOrder
{
    public function __construct(
        private readonly OrderNumbers $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(OpenOrderInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $register = Register::with('location')->findOrFail($in->registerId);

            $shift = Shift::openFor($register->id) ?? throw new NoOpenShift($register->id);

            $location = $register->location;
            // The local calendar day at the store, stored so every report groups by it.
            $businessDate = now($location->timezone)->toDateString();

            $order = Order::create([
                'number' => $this->numbers->next($location, $businessDate),
                'location_id' => $location->id,
                'register_id' => $register->id,
                'shift_id' => $shift->id,
                'business_date' => $businessDate,
                'opened_by' => $in->actorId,
                'customer_id' => $in->customerId,
                'table_ref' => $in->tableRef,
                'status' => 'open',
                'prices_include_tax' => $location->prices_include_tax,
                // Explicit, not relied-on-as-DB-default: Order uses HasUuids (a
                // non-incrementing key), so Eloquent never re-SELECTs the row after
                // INSERT — the in-memory model would otherwise carry null here instead
                // of the schema's default 0, and every zero-line order would report a
                // null version and null totals until the next fetch.
                'subtotal_cents' => 0,
                'discount_cents' => 0,
                'tax_cents' => 0,
                'total_cents' => 0,
                'paid_cents' => 0,
                'version' => 0,
                'opened_at' => now(),
            ]);

            $this->audit->record('order.open', $order, $in->actorId, registerId: $register->id);

            return $order;
        });
    }
}
