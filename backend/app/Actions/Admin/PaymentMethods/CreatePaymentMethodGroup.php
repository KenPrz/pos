<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Domain\Audit\AuditLogger;
use App\Models\PaymentMethodGroup;
use Illuminate\Support\Facades\DB;

final class CreatePaymentMethodGroup
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreatePaymentMethodGroupInput $in): PaymentMethodGroup
    {
        return DB::transaction(function () use ($in): PaymentMethodGroup {
            $group = PaymentMethodGroup::create([
                'location_id' => $in->locationId,
                'code' => $in->code,
                'name' => $in->name,
                'driver' => $in->driver,
                'sort_order' => $in->sortOrder,
                'is_active' => $in->isActive,
            ]);

            $this->audit->record('admin.payment_method_group.create', $group, $in->actorId, [
                'location_id' => $in->locationId, 'code' => $in->code, 'driver' => $in->driver,
            ]);

            return $group;
        });
    }
}
