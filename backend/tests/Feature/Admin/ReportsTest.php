<?php

// backend/tests/Feature/Admin/ReportsTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Orders\VoidOrder;
use App\Actions\Orders\VoidOrderInput;
use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Actions\Payments\VoidPayment;
use App\Actions\Payments\VoidPaymentInput;
use App\Actions\Refunds\RefundLineInput;
use App\Actions\Refunds\RefundOrder;
use App\Actions\Refunds\RefundOrderInput;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\Roles;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashierA = staffWithRole($this->location, Roles::CASHIER);
    $this->cashierB = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);

    app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $this->register->id, openingFloatCents: 0, actorId: $this->cashierA->id,
    ));

    $admin = User::factory()->create(['email' => 'admin@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    $this->today = now()->toDateString();
});

// Pest file-scoped functions collide across test files if two share a name — namespaced
// per task to keep this file free to move or be copied.
function reportsAddLine(object $t, string $orderId, string $variantId, string $qty, User $actor): Order
{
    return app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $orderId, registerId: $t->register->id, variantId: $variantId, qty: $qty,
        expectedVersion: Order::findOrFail($orderId)->version, actorId: $actor->id,
    ))->order;
}

function reportsPay(object $t, string $orderId, string $driver, int $amountCents, User $actor): Order
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $orderId, registerId: $t->register->id, driver: $driver,
        amountCents: $amountCents, tenderedCents: $driver === 'cash' ? $amountCents : null,
        reference: null, expectedVersion: Order::findOrFail($orderId)->version, actorId: $actor->id,
    ))->order;
}

it('reports sales by day, user and category off the ledgers — voided money and lines never leak in', function (): void {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $drinkProduct = Product::factory()->create(['category_id' => $drinks->id]);
    $drinkVariant = ProductVariant::factory()->untracked()->create([
        'product_id' => $drinkProduct->id, 'price_cents' => 1000, 'tax_rate_id' => null,
    ]);

    $foodProduct = Product::factory()->create(['category_id' => null]);
    $foodVariant = ProductVariant::factory()->untracked()->create([
        'product_id' => $foodProduct->id, 'price_cents' => 500, 'tax_rate_id' => null,
    ]);

    // Sale 1: cashier A, cash, 1000¢.
    $orderA = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashierA->id]);
    $orderA = reportsAddLine($this, $orderA->id, $drinkVariant->id, '1', $this->cashierA);
    expect($orderA->total_cents)->toBe(1000);
    $orderA = reportsPay($this, $orderA->id, 'cash', 1000, $this->cashierA);
    expect($orderA->status)->toBe(OrderStatus::Closed);

    // Sale 2: cashier B, card, 500¢ — then a 300¢ partial refund by B (600/1000 of the
    // line's 500¢ total = exactly 300¢, no rounding to hide a bug behind).
    $orderB = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashierB->id]);
    $orderB = reportsAddLine($this, $orderB->id, $foodVariant->id, '1', $this->cashierB);
    expect($orderB->total_cents)->toBe(500);
    $orderB = reportsPay($this, $orderB->id, 'external_card', 500, $this->cashierB);
    $lineB = $orderB->lines->first();

    $refund = app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $orderB->id, registerId: $this->register->id, driver: 'external_card',
        reason: 'partial return', lines: [new RefundLineInput($lineB->id, '0.600', restock: false)],
        actorId: $this->cashierB->id,
    ));
    expect($refund->amount_cents)->toBe(300);

    // A third order: paid, then the payment voided (reopens it), then the order itself
    // voided. Its (voided) payment must never count in `day` — its status has left
    // 'captured' — and its line must never appear in `category`, since that reads only
    // non-voided lines of CLOSED orders.
    $orderC = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashierA->id]);
    $orderC = reportsAddLine($this, $orderC->id, $drinkVariant->id, '1', $this->cashierA);
    $orderC = reportsPay($this, $orderC->id, 'cash', 1000, $this->cashierA);
    $payment = $orderC->payments()->sole();
    $orderC = app(VoidPayment::class)->execute(new VoidPaymentInput(
        paymentId: $payment->id, registerId: $this->register->id, reason: 'mistake', actorId: $this->supervisor->id,
    ))->order;
    app(VoidOrder::class)->execute(new VoidOrderInput(
        orderId: $orderC->id, registerId: $this->register->id, reason: 'walkout',
        expectedVersion: $orderC->version, actorId: $this->supervisor->id,
    ));
    expect(DB::table('payments')->where('id', $payment->id)->value('status'))->toBe('voided');
    expect(Order::findOrFail($orderC->id)->status)->toBe(OrderStatus::Voided);

    $qs = "from={$this->today}&to={$this->today}&location_id={$this->location->id}";

    // --- day ---
    $day = $this->getJson("/api/v1/admin/reports/sales?{$qs}&group_by=day", $this->headers)
        ->assertOk()->json('data');
    expect($day['basis'])->toBe('ledger');
    $dayRow = collect($day['rows'])->firstWhere('bucket', $this->today);
    expect($dayRow)->not->toBeNull()
        ->and($dayRow['gross_cents'])->toBe(1500)
        ->and($dayRow['refunds_cents'])->toBe(300)
        ->and($dayRow['net_cents'])->toBe(1200)
        ->and($dayRow['orders_closed'])->toBe(2);
    expect($day['totals'])->toBe([
        'orders_closed' => 2, 'gross_cents' => 1500, 'refunds_cents' => 300, 'net_cents' => 1200,
    ]);

    // --- user ---
    $user = $this->getJson("/api/v1/admin/reports/sales?{$qs}&group_by=user", $this->headers)
        ->assertOk()->json('data');
    expect($user['basis'])->toBe('ledger');
    $rowA = collect($user['rows'])->firstWhere('bucket', $this->cashierA->name);
    $rowB = collect($user['rows'])->firstWhere('bucket', $this->cashierB->name);
    expect($rowA['gross_cents'])->toBe(1000)->and($rowA['refunds_cents'])->toBe(0)->and($rowA['net_cents'])->toBe(1000)
        ->and($rowB['gross_cents'])->toBe(500)->and($rowB['refunds_cents'])->toBe(300)->and($rowB['net_cents'])->toBe(200);

    // --- category ---
    $category = $this->getJson("/api/v1/admin/reports/sales?{$qs}&group_by=category", $this->headers)
        ->assertOk()->json('data');
    expect($category['basis'])->toBe('lines');
    $drinksRow = collect($category['rows'])->firstWhere('bucket', 'Drinks');
    $uncatRow = collect($category['rows'])->firstWhere('bucket', 'Uncategorized');
    expect($drinksRow['line_total_cents'])->toBe(1000)->and($drinksRow['qty_sold'])->toBe('1.000')
        ->and($uncatRow['line_total_cents'])->toBe(500)->and($uncatRow['qty_sold'])->toBe('1.000');
    // Only orderA's drink line and orderB's food line count — orderC (voided) contributes
    // nothing, so the two buckets found above are the whole report.
    expect($category['rows'])->toHaveCount(2);
    expect($category['totals'])->toBe(['qty_sold' => '2.000', 'line_total_cents' => 1500]);

    // --- validation ---
    $this->getJson("/api/v1/admin/reports/sales?{$qs}", $this->headers)
        ->assertStatus(400); // missing group_by

    $farFuture = now()->addDays(400)->toDateString();
    $this->getJson("/api/v1/admin/reports/sales?from={$this->today}&to={$farFuture}&location_id={$this->location->id}&group_by=day", $this->headers)
        ->assertStatus(400); // range > 366 days
});

it('reports stock against the config low-stock threshold, and low_only filters to it', function (): void {
    config(['pos.stock.low_threshold' => 5]);

    $low = ProductVariant::factory()->create(['sku' => 'LOW-1', 'track_inventory' => true]);
    DB::table('stock_levels')->insert(['variant_id' => $low->id, 'location_id' => $this->location->id, 'qty' => 5, 'updated_at' => now()]);

    $ok = ProductVariant::factory()->create(['sku' => 'OK-1', 'track_inventory' => true]);
    DB::table('stock_levels')->insert(['variant_id' => $ok->id, 'location_id' => $this->location->id, 'qty' => 6, 'updated_at' => now()]);

    $rows = $this->getJson("/api/v1/admin/reports/stock?location_id={$this->location->id}", $this->headers)
        ->assertOk()->json('data.rows');
    $lowRow = collect($rows)->firstWhere('sku', 'LOW-1');
    $okRow = collect($rows)->firstWhere('sku', 'OK-1');
    expect($lowRow['qty'])->toBe('5.000')->and($lowRow['low'])->toBeTrue()
        ->and($okRow['qty'])->toBe('6.000')->and($okRow['low'])->toBeFalse();

    $lowOnly = $this->getJson("/api/v1/admin/reports/stock?location_id={$this->location->id}&low_only=true", $this->headers)
        ->assertOk()->json('data.rows');
    expect(collect($lowOnly)->pluck('sku')->all())->toBe(['LOW-1']);

    // The threshold is config, not a database row — dropping it below the 5-unit level
    // flips `low` for the same row without touching stock_levels at all.
    config(['pos.stock.low_threshold' => 4]);
    $afterLowerThreshold = $this->getJson("/api/v1/admin/reports/stock?location_id={$this->location->id}", $this->headers)
        ->assertOk()->json('data.rows');
    expect(collect($afterLowerThreshold)->firstWhere('sku', 'LOW-1')['low'])->toBeFalse();
});

it('a non-admin token gets 403 on both report routes', function (): void {
    $staff = User::factory()->create(['email' => 's@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];
    $this->getJson('/api/v1/admin/reports/sales?from=2026-01-01&to=2026-01-01&location_id='.$this->location->id.'&group_by=day', $headers)
        ->assertStatus(403);
    $this->getJson('/api/v1/admin/reports/stock?location_id='.$this->location->id, $headers)
        ->assertStatus(403);
});
