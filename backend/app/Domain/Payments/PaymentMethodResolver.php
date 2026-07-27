<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Exceptions\Domain\PaymentMethodInactive;
use App\Exceptions\Domain\PaymentMethodUnknown;
use Illuminate\Support\Facades\DB;

/**
 * Turns the code a till sends into the driver the payment path already understands.
 * This is the only place the group→driver indirection is read, which is what keeps
 * PaymentDriver, DriverRegistry and Capabilities untouched by the taxonomy above them.
 *
 * Read-only and cheap: one indexed join, called inside the caller's transaction.
 */
final class PaymentMethodResolver
{
    public function resolve(string $locationId, string $code): ResolvedPaymentMethod
    {
        $row = DB::table('payment_methods as pm')
            ->join('payment_method_groups as g', 'g.id', '=', 'pm.group_id')
            ->where('pm.location_id', $locationId)
            ->where('pm.code', $code)
            ->first([
                'pm.id', 'pm.code', 'pm.name', 'pm.is_active as method_active',
                'g.code as group_code', 'g.driver', 'g.is_active as group_active',
            ]);

        if ($row === null) {
            throw new PaymentMethodUnknown($locationId, $code);
        }

        // An archived GROUP hides its methods without touching their rows — one switch
        // for "we stopped taking cards" instead of five.
        if (! $row->method_active || ! $row->group_active) {
            throw new PaymentMethodInactive($locationId, $code);
        }

        return new ResolvedPaymentMethod(
            id: (string) $row->id,
            code: (string) $row->code,
            name: (string) $row->name,
            groupCode: (string) $row->group_code,
            driver: (string) $row->driver,
        );
    }
}
