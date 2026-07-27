<?php

declare(strict_types=1);

use App\Actions\Refunds\RefundLineInput;
use App\Actions\Refunds\RefundOrder;
use App\Actions\Refunds\RefundOrderInput;
use App\Domain\Rbac\Roles;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodGroup;
use App\Models\ProductVariant;
use App\Models\Shift;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('allows the same code at two different locations', function (): void {
    $a = Location::factory()->create(['code' => 'AAA']);
    $b = Location::factory()->create(['code' => 'BBB']);

    foreach ([$a, $b] as $location) {
        $group = PaymentMethodGroup::factory()->create([
            'location_id' => $location->id, 'code' => 'CASH', 'driver' => 'cash',
        ]);
        PaymentMethod::factory()->create([
            'location_id' => $location->id, 'group_id' => $group->id, 'code' => 'CASH',
        ]);
    }

    expect(PaymentMethod::query()->where('code', 'CASH')->count())->toBe(2);
});

it('refuses two groups sharing a code at one location', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    PaymentMethodGroup::factory()->create(['location_id' => $location->id, 'code' => 'CASH']);

    expect(fn () => PaymentMethodGroup::factory()->create([
        'location_id' => $location->id, 'code' => 'CASH',
    ]))->toThrow(QueryException::class);
});

it('refuses two methods sharing a code at one location', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);
    $group = PaymentMethodGroup::factory()->create(['location_id' => $location->id, 'code' => 'CASH']);
    PaymentMethod::factory()->create([
        'location_id' => $location->id, 'group_id' => $group->id, 'code' => 'CASH',
    ]);

    expect(fn () => PaymentMethod::factory()->create([
        'location_id' => $location->id, 'group_id' => $group->id, 'code' => 'CASH',
    ]))->toThrow(QueryException::class);
});

it('refuses a method pointing at another location\'s group', function (): void {
    $a = Location::factory()->create(['code' => 'AAA']);
    $b = Location::factory()->create(['code' => 'BBB']);
    $groupAtA = PaymentMethodGroup::factory()->create(['location_id' => $a->id, 'code' => 'CASH']);

    // The composite FK (group_id, location_id) is what rejects this, not a validator.
    expect(fn () => PaymentMethod::factory()->create([
        'location_id' => $b->id, 'group_id' => $groupAtA->id, 'code' => 'CASH',
    ]))->toThrow(QueryException::class);
});

it('refuses a driver outside the registry', function (): void {
    $location = Location::factory()->create(['code' => 'AAA']);

    expect(fn () => PaymentMethodGroup::factory()->create([
        'location_id' => $location->id, 'code' => 'CRYPTO', 'driver' => 'bitcoin',
    ]))->toThrow(QueryException::class);
});

it('refuses to point a refund at a method from another location', function (): void {
    // Defence-in-depth, not a live hole: RefundOrder resolves the method against the
    // acting register's location, so no code path produces this today. The constraint is
    // what stops a FUTURE writer from forgetting that rule — same guarantee
    // payment_methods.group_id already has.
    $location = provisionedLocation(['code' => 'AAA']);
    $register = registerAt($location);
    $supervisor = staffWithRole($location, Roles::SUPERVISOR);
    $shift = Shift::factory()->create(['register_id' => $register->id]);

    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 1000]);
    $order = Order::factory()->forRegister($register)->create([
        'opened_by' => $supervisor->id, 'status' => OrderStatus::Closed,
        'subtotal_cents' => 1000, 'total_cents' => 1000, 'paid_cents' => 1000,
    ]);
    $line = $order->lines()->create([
        'variant_id' => $variant->id, 'name_snapshot' => 'Thing', 'sku_snapshot' => 'SKU-1',
        'qty' => '1', 'unit_price_cents' => 1000, 'line_total_cents' => 1000,
        'tax_cents' => 0, 'tax_rate_micros' => 0,
    ]);

    $refund = app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $order->id, registerId: $register->id, paymentMethodCode: 'CASH',
        reason: 'Faulty', lines: [new RefundLineInput($line->id, '1', restock: false)],
        actorId: $supervisor->id,
    ));

    // Another location's CASH method — a legal row there, illegal on THIS refund.
    $elsewhere = provisionedLocation(['code' => 'BBB']);
    $foreignMethod = PaymentMethod::query()
        ->where('location_id', $elsewhere->id)->where('code', 'CASH')->firstOrFail();

    expect(fn () => DB::table('refunds')->where('id', $refund->id)
        ->update(['payment_method_id' => $foreignMethod->id]))
        ->toThrow(QueryException::class);
});
