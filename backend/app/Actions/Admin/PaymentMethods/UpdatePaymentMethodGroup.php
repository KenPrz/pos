<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Domain\Audit\AuditLogger;
use App\Models\PaymentMethodGroup;
use Illuminate\Support\Facades\DB;

/**
 * Name, sort order and archive only. `code` and `driver` never arrive here — the code is
 * a wire identifier and a report key, and the driver IS the behaviour every method under
 * the group inherits. Changing either is archive-and-recreate.
 */
final class UpdatePaymentMethodGroup
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdatePaymentMethodGroupInput $in): PaymentMethodGroup
    {
        return DB::transaction(function () use ($in): PaymentMethodGroup {
            $group = PaymentMethodGroup::query()->lockForUpdate()->findOrFail($in->groupId);

            $group->fill($in->changes)->save();

            $this->audit->record('admin.payment_method_group.update', $group, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $group;
        });
    }
}
