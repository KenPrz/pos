<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use Illuminate\Support\Facades\DB;

/**
 * One location's default tender set. The counterpart to RoleProvisioner, and for the
 * same reason: RBAC v2 fixed a confirmed bug where a location created in the back office
 * got no roles and was unusable. A location with no payment methods is that bug with a
 * different noun — every tender at it would 422.
 *
 * Idempotent by code, so re-running it never duplicates a row and never overwrites a
 * name an admin has since edited.
 */
final class PaymentMethodProvisioner
{
    /** @var list<array{code: string, name: string, driver: string, method: string, methodName: string}> */
    public const array DEFAULTS = [
        ['code' => 'CASH', 'name' => 'Cash', 'driver' => 'cash', 'method' => 'CASH', 'methodName' => 'Cash'],
        ['code' => 'CARD', 'name' => 'Cards', 'driver' => 'external_card', 'method' => 'CARD', 'methodName' => 'Card'],
    ];

    public function provisionForLocation(string $locationId): void
    {
        DB::transaction(function () use ($locationId): void {
            foreach (self::DEFAULTS as $sort => $default) {
                $group = PaymentMethodGroup::query()
                    ->where('location_id', $locationId)
                    ->where('code', $default['code'])
                    ->first();

                $group ??= PaymentMethodGroup::create([
                    'location_id' => $locationId,
                    'code' => $default['code'],
                    'name' => $default['name'],
                    'driver' => $default['driver'],
                    'sort_order' => $sort,
                    'is_active' => true,
                ]);

                $exists = PaymentMethod::query()
                    ->where('location_id', $locationId)
                    ->where('code', $default['method'])
                    ->exists();

                if (! $exists) {
                    PaymentMethod::create([
                        'location_id' => $locationId,
                        'group_id' => $group->id,
                        'code' => $default['method'],
                        'name' => $default['methodName'],
                        'sort_order' => 0,
                        'is_active' => true,
                    ]);
                }
            }
        });
    }
}
