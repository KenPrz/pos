<?php

declare(strict_types=1);

namespace App\Actions\Admin\PaymentMethods;

use App\Domain\Audit\AuditLogger;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

/**
 * Name, sort order and archive only. `code` and `group_id` never arrive here: the code is
 * a wire identifier and a report key, and the group carries the driver — moving a method
 * between groups would change its behaviour and re-bucket its history.
 */
final class UpdatePaymentMethod
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(UpdatePaymentMethodInput $in): PaymentMethod
    {
        return DB::transaction(function () use ($in): PaymentMethod {
            $method = PaymentMethod::query()->lockForUpdate()->findOrFail($in->methodId);

            $method->fill($in->changes)->save();

            $this->audit->record('admin.payment_method.update', $method, $in->actorId, [
                'changed' => array_keys($in->changes),
            ]);

            return $method;
        });
    }
}
