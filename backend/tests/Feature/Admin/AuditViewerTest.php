<?php

// backend/tests/Feature/Admin/AuditViewerTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\ApplyDiscount;
use App\Actions\Orders\ApplyDiscountInput;
use App\Actions\Orders\VoidOrder;
use App\Actions\Orders\VoidOrderInput;
use App\Domain\Audit\AuditLogger;
use App\Domain\Rbac\Roles;
use App\Models\Discount;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);

    $admin = User::factory()->create(['email' => 'admin@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 1000]);
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $this->order->id, registerId: $this->register->id, variantId: $variant->id,
        qty: '1', expectedVersion: Order::findOrFail($this->order->id)->version, actorId: $this->cashier->id,
    ));

    // A discount, applied by the cashier — an OrderDiscount-entity row.
    $discount = Discount::factory()->fixed(100)->create();
    app(ApplyDiscount::class)->execute(new ApplyDiscountInput(
        orderId: $this->order->id, registerId: $this->register->id, discountId: $discount->id,
        orderLineId: null, reason: 'loyalty', expectedVersion: Order::findOrFail($this->order->id)->version,
        actorId: $this->cashier->id,
    ));

    // A void, by the supervisor — an Order-entity row.
    $this->voidedOrder = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    app(VoidOrder::class)->execute(new VoidOrderInput(
        orderId: $this->voidedOrder->id, registerId: $this->register->id, reason: 'walkout',
        expectedVersion: Order::findOrFail($this->voidedOrder->id)->version, actorId: $this->supervisor->id,
    ));
});

it('filters by entity_type to Order rows only, with user_name resolved', function (): void {
    $rows = $this->getJson('/api/v1/admin/audit?entity_type=Order', $this->headers)
        ->assertOk()->json('data.rows');

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['entity_type'])->toBe('Order');
    }

    $voidRow = collect($rows)->firstWhere('action', 'order.void');
    expect($voidRow)->not->toBeNull()
        ->and($voidRow['entity_id'])->toBe($this->voidedOrder->id)
        ->and($voidRow['user_name'])->toBe($this->supervisor->name)
        ->and($voidRow['payload']['reason'])->toBe('walkout');
});

it('filters by user_id', function (): void {
    $rows = $this->getJson('/api/v1/admin/audit?user_id='.$this->supervisor->id, $this->headers)
        ->assertOk()->json('data.rows');

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['user_name'])->toBe($this->supervisor->name);
    }
});

it('filters by action', function (): void {
    $rows = $this->getJson('/api/v1/admin/audit?action=order.discount.apply', $this->headers)
        ->assertOk()->json('data.rows');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['action'])->toBe('order.discount.apply')
        ->and($rows[0]['entity_type'])->toBe('OrderDiscount')
        ->and($rows[0]['payload']['reason'])->toBe('loyalty');
});

it('paginates with has_more once past 50 rows', function (): void {
    $logger = app(AuditLogger::class);
    for ($i = 0; $i < 51; $i++) {
        $logger->record('test.seed', 'TestEntity', $this->cashier->id);
    }

    $page1 = $this->getJson('/api/v1/admin/audit?action=test.seed&page=1', $this->headers)
        ->assertOk()->json('data');
    expect($page1['rows'])->toHaveCount(50)
        ->and($page1['has_more'])->toBeTrue()
        ->and($page1['page'])->toBe(1);

    $page2 = $this->getJson('/api/v1/admin/audit?action=test.seed&page=2', $this->headers)
        ->assertOk()->json('data');
    expect($page2['rows'])->toHaveCount(1)
        ->and($page2['has_more'])->toBeFalse()
        ->and($page2['page'])->toBe(2);
});

it('a non-admin token gets 403', function (): void {
    $staff = User::factory()->create(['email' => 's@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];
    $this->getJson('/api/v1/admin/audit', $headers)->assertStatus(403);
});
