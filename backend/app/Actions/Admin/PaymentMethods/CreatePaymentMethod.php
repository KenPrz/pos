<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Domain\Audit\AuditLogger;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

final class CreatePaymentMethod
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(CreatePaymentMethodInput $in): PaymentMethod
    {
        return DB::transaction(function () use ($in): PaymentMethod {
            $method = PaymentMethod::create([
                'location_id' => $in->locationId,
                'group_id' => $in->groupId,
                'code' => $in->code,
                'name' => $in->name,
                'sort_order' => $in->sortOrder,
                'is_active' => $in->isActive,
            ]);

            $this->audit->record('admin.payment_method.create', $method, $in->actorId, [
                'location_id' => $in->locationId, 'group_id' => $in->groupId, 'code' => $in->code,
            ]);

            return $method;
        });
    }
}
