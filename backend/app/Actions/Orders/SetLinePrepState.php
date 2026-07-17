<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\LineAlreadyVoided;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * The coursing verbs, mapped from industry practice (spec): pending = held,
 * in_progress = fired, ready = on the pass. Deliberately no If-Match and no version
 * bump — the kitchen marking food ready must never invalidate the till mid-tender.
 */
final class SetLinePrepState
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(SetLinePrepStateInput $in): OrderLine
    {
        return DB::transaction(function () use ($in): OrderLine {
            $locationId = Register::findOrFail($in->registerId)->location_id;
            $order = Order::whereKey($in->orderId)->where('location_id', $locationId)->firstOrFail();

            /** @var OrderLine $line */
            $line = $order->lines()->whereKey($in->lineId)->firstOrFail();
            if ($line->voided_at !== null) {
                throw new LineAlreadyVoided($line->id);
            }

            $from = $line->prep_state;
            $line->forceFill(['prep_state' => $in->state])->save();

            $this->audit->record('order.line.prep', $line, $in->actorId, [
                'order_id' => $order->id, 'from' => $from, 'to' => $in->state,
            ], registerId: $in->registerId);

            return $line->refresh()->setRelation('order', $order->fresh(['lines', 'discounts']));
        });
    }
}
