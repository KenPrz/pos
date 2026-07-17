<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OpenOrderLock;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/** Moves a party between tables. Open orders only; the ref is wayfinding, not money. */
final class SetTableRef
{
    public function __construct(
        private readonly OpenOrderLock $lock,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(SetTableRefInput $in): Order
    {
        return DB::transaction(function () use ($in): Order {
            $order = $this->lock->acquire($in->orderId, $in->registerId, $in->expectedVersion);

            $from = $order->table_ref;
            $order->forceFill(['table_ref' => $in->tableRef, 'version' => $order->version + 1])->save();

            $this->audit->record('order.table_ref', $order, $in->actorId, [
                'from' => $from, 'to' => $in->tableRef,
            ], registerId: $in->registerId);

            return $order->fresh(['lines', 'discounts']);
        });
    }
}
