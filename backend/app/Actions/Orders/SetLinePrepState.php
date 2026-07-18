<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\Domain\FiredLineRequiresSupervisor;
use App\Exceptions\Domain\LineAlreadyVoided;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

/**
 * The coursing verbs, mapped from industry practice (spec): pending = held,
 * in_progress = fired, ready = on the pass. Deliberately no If-Match and no version
 * bump — the kitchen marking food ready must never invalidate the till mid-tender.
 *
 * Forward transitions are ungated beyond `order.line.update`. Downgrading OUT of a fired
 * state (in_progress/ready) back toward an earlier one takes `order.line.void` — the same
 * permission shrinking a fired line takes in UpdateLineQty — because uncooking a line on
 * paper is the front half of the same fraud path: unfire it, then quietly shrink it past
 * the qty gate (docs/05-rbac.md).
 */
final class SetLinePrepState
{
    /** Coursing order; a move to a lower rank is a downgrade. */
    private const RANK = ['pending' => 0, 'in_progress' => 1, 'ready' => 2];

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

            // A downgrade out of a fired state needs the void permission (see the class
            // docblock). Forward moves — and a no-op onto the same state — are ungated.
            if (self::RANK[$in->state] < (self::RANK[$from] ?? 0) && ! $in->actorMayVoidLines) {
                throw new FiredLineRequiresSupervisor($line->id);
            }

            $line->forceFill(['prep_state' => $in->state])->save();

            $this->audit->record('order.line.prep', $line, $in->actorId, [
                'order_id' => $order->id, 'from' => $from, 'to' => $in->state,
            ], registerId: $in->registerId);

            return $line->refresh()->setRelation('order', $order->fresh(['lines', 'discounts']));
        });
    }
}
