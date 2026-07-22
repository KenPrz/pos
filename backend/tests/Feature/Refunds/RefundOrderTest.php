<?php

declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Actions\Payments\TakePayment;
use App\Actions\Payments\TakePaymentInput;
use App\Actions\Refunds\RefundLineInput;
use App\Actions\Refunds\RefundOrder;
use App\Actions\Refunds\RefundOrderInput;
use App\Domain\Money\Quantity;
use App\Domain\Rbac\Roles;
use App\Domain\Shifts\ShiftTotals;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\OrderClosed;
use App\Exceptions\Domain\RefundExceedsOriginal;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // Prices exclude tax at this location, so a 0%-tax line is exact round cents and an
    // 8.875%-tax line (test d) has a single, predictable place tax gets added.
    $this->location = provisionedLocation(['prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->shift = Shift::factory()->create(['register_id' => $this->register->id]);

    $this->variant = ProductVariant::factory()->create(['price_cents' => 1000, 'track_inventory' => true]);
    DB::transaction(fn () => app(StockLedger::class)->receive(
        $this->variant->id, $this->location->id, Quantity::fromString('10'),
    ));

    // A real sale, through the real actions: scan 2, pay in full. Stock goes 10 -> 8 and
    // the order closes, which is the only state a refund is legal against.
    $this->order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $this->order = t8AddLine($this, $this->order->id, $this->variant->id, '2');
    $this->line = $this->order->lines->first();
    $this->order = t8Pay($this, $this->order->id, 2000);

    expect($this->order->status)->toBe(OrderStatus::Closed)
        ->and($this->order->paid_cents)->toBe(2000);
    expect(DB::table('stock_levels')->where('variant_id', $this->variant->id)->value('qty'))->toBe('8.000');
});

// Pest file-scoped functions collide across test files if two share a name — namespaced
// per task to keep this file free to move or be copied.
function t8AddLine(object $t, string $orderId, string $variantId, string $qty): Order
{
    return app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $orderId, variantId: $variantId, qty: $qty,
        expectedVersion: Order::findOrFail($orderId)->version, actorId: $t->cashier->id, registerId: $t->register->id,
    ))->order;
}

function t8Pay(object $t, string $orderId, int $amountCents): Order
{
    return app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $orderId, registerId: $t->register->id, driver: 'cash',
        amountCents: $amountCents, tenderedCents: $amountCents, reference: null,
        expectedVersion: Order::findOrFail($orderId)->version, actorId: $t->cashier->id,
    ))->order;
}

/** @param list<RefundLineInput> $lines */
function t8Refund(object $t, array $lines, ?string $reason = null): Refund
{
    return app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $t->order->id, registerId: $t->register->id, driver: 'cash',
        reason: $reason ?? 'Customer return', lines: $lines, actorId: $t->supervisor->id,
    ));
}

it('a full-line refund with restock returns the money, restocks, and never touches the original order', function (): void {
    $originalVersion = Order::findOrFail($this->order->id)->version;

    $refund = t8Refund($this, [new RefundLineInput($this->line->id, '2', restock: true)]);

    expect($refund->amount_cents)->toBe(2000)
        ->and($refund->driver)->toBe('cash')
        ->and($refund->original_order_id)->toBe($this->order->id)
        ->and($refund->business_date)->not->toBeNull()
        ->and($refund->lines)->toHaveCount(1);

    $refundLine = $refund->lines->first();
    expect($refundLine->qty)->toBe('2')
        ->and($refundLine->amount_cents)->toBe(2000)
        ->and($refundLine->restock)->toBeTrue();

    expect(DB::table('stock_levels')->where('variant_id', $this->variant->id)->value('qty'))->toBe('10.000');
    $this->assertDatabaseHas('stock_movements', [
        'reason' => 'refund', 'ref_type' => 'refund_line', 'ref_id' => $refundLine->id,
    ]);
    $this->assertDatabaseHas('audit_log', ['action' => 'refund.create', 'entity_id' => $refund->id]);

    // The original order is new rows away from this, never a mutation.
    $original = Order::findOrFail($this->order->id);
    expect($original->status)->toBe(OrderStatus::Closed)
        ->and($original->paid_cents)->toBe(2000)
        ->and($original->version)->toBe($originalVersion);
});

it('a partial refund derives its own amount, and a second partial exactly exhausts the line', function (): void {
    $first = t8Refund($this, [new RefundLineInput($this->line->id, '1', restock: true)]);
    expect($first->amount_cents)->toBe(1000);

    $second = t8Refund($this, [new RefundLineInput($this->line->id, '1', restock: true)]);
    expect($second->amount_cents)->toBe(1000);

    expect(fn () => t8Refund($this, [new RefundLineInput($this->line->id, '1', restock: true)]))
        ->toThrow(RefundExceedsOriginal::class);
});

it('reports the over-refund with the cumulative and requested quantities as strings', function (): void {
    t8Refund($this, [new RefundLineInput($this->line->id, '2', restock: true)]);

    try {
        t8Refund($this, [new RefundLineInput($this->line->id, '1', restock: true)]);
        expect(false)->toBeTrue('expected RefundExceedsOriginal to be thrown');
    } catch (RefundExceedsOriginal $e) {
        expect($e->details())->toBe([
            'original_order_line_id' => $this->line->id,
            'original_qty' => '2.000',
            'already_refunded_qty' => '2.000',
            'requested_qty' => '1.000',
        ]);
    }
});

it('declining restock refunds the money but writes no stock movement', function (): void {
    $refund = t8Refund($this, [new RefundLineInput($this->line->id, '2', restock: false)]);

    expect($refund->amount_cents)->toBe(2000)
        ->and($refund->lines->first()->restock)->toBeFalse();

    // Stock never moved: still what the sale left it at, not restored.
    expect(DB::table('stock_levels')->where('variant_id', $this->variant->id)->value('qty'))->toBe('8.000');
    $this->assertDatabaseMissing('stock_movements', ['ref_type' => 'refund_line']);
});

it('a taxed line refunds line_total + tax exactly', function (): void {
    $taxRate = TaxRate::factory()->nyc()->create();   // 8.875%
    $variant = ProductVariant::factory()->create([
        'price_cents' => 1000, 'track_inventory' => true, 'tax_rate_id' => $taxRate->id,
    ]);
    DB::transaction(fn () => app(StockLedger::class)->receive($variant->id, $this->location->id, Quantity::fromString('10')));

    $order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $order = t8AddLine($this, $order->id, $variant->id, '2');
    $line = $order->lines->first();

    expect($line->line_total_cents)->toBe(2000)
        ->and($line->tax_cents)->toBe(178);   // round_half_up(2000 * 0.08875) = 178

    $order = t8Pay($this, $order->id, $order->total_cents);
    expect($order->total_cents)->toBe(2178);

    $refund = app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $order->id, registerId: $this->register->id, driver: 'cash',
        reason: 'Customer return', lines: [new RefundLineInput($line->id, '2', restock: true)],
        actorId: $this->supervisor->id,
    ));

    expect($refund->amount_cents)->toBe(2178);
});

it("piecewise refunds sum exactly to the line's total — no invented penny", function (): void {
    // price 500 x qty 2 = 1000; tax = round_half_up(1000 * 0.08875) = 89 -> total 1089.
    // Deriving each refund purely from qty/origQty (545 + 545 = 1090) invents a penny;
    // capping the second refund at what's left of the line (544) keeps the sum exact.
    $taxRate = TaxRate::factory()->create(['rate_micros' => 88_750]);
    $variant = ProductVariant::factory()->create([
        'price_cents' => 500, 'track_inventory' => true, 'tax_rate_id' => $taxRate->id,
    ]);
    DB::transaction(fn () => app(StockLedger::class)->receive($variant->id, $this->location->id, Quantity::fromString('10')));

    $order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $order = t8AddLine($this, $order->id, $variant->id, '2');
    $line = $order->lines->first();

    expect($line->line_total_cents)->toBe(1000)
        ->and($line->tax_cents)->toBe(89);

    $order = t8Pay($this, $order->id, $order->total_cents);
    expect($order->total_cents)->toBe(1089);

    $first = app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $order->id, registerId: $this->register->id, driver: 'cash',
        reason: 'Customer return', lines: [new RefundLineInput($line->id, '1', restock: true)],
        actorId: $this->supervisor->id,
    ));
    expect($first->amount_cents)->toBe(545);

    $second = app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $order->id, registerId: $this->register->id, driver: 'cash',
        reason: 'Customer return', lines: [new RefundLineInput($line->id, '1', restock: true)],
        actorId: $this->supervisor->id,
    ));
    expect($second->amount_cents)->toBe(544);

    expect((int) DB::table('refund_lines')->where('original_order_line_id', $line->id)->sum('amount_cents'))
        ->toBe(1089);

    expect(fn () => app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $order->id, registerId: $this->register->id, driver: 'cash',
        reason: 'Customer return', lines: [new RefundLineInput($line->id, '1', restock: true)],
        actorId: $this->supervisor->id,
    )))->toThrow(RefundExceedsOriginal::class);
});

it("piecewise refunds sum exactly to the line's total — no lost penny", function (): void {
    // price 333 x qty 3 = 999; tax = round_half_up(999 * 0.10) = 100 -> total 1099.
    // Deriving each refund purely from qty/origQty (366 x 3 = 1098) loses a penny;
    // taking the final refund as the exact remainder (367) keeps the sum exact.
    $taxRate = TaxRate::factory()->create(['rate_micros' => 100_000]);
    $variant = ProductVariant::factory()->create([
        'price_cents' => 333, 'track_inventory' => true, 'tax_rate_id' => $taxRate->id,
    ]);
    DB::transaction(fn () => app(StockLedger::class)->receive($variant->id, $this->location->id, Quantity::fromString('10')));

    $order = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $order = t8AddLine($this, $order->id, $variant->id, '3');
    $line = $order->lines->first();

    expect($line->line_total_cents)->toBe(999)
        ->and($line->tax_cents)->toBe(100);

    $order = t8Pay($this, $order->id, $order->total_cents);
    expect($order->total_cents)->toBe(1099);

    $amounts = [];
    foreach (range(1, 3) as $i) {
        $refund = app(RefundOrder::class)->execute(new RefundOrderInput(
            originalOrderId: $order->id, registerId: $this->register->id, driver: 'cash',
            reason: 'Customer return', lines: [new RefundLineInput($line->id, '1', restock: true)],
            actorId: $this->supervisor->id,
        ));
        $amounts[] = $refund->amount_cents;
    }

    expect($amounts)->toBe([366, 366, 367])
        ->and(array_sum($amounts))->toBe(1099);

    expect((int) DB::table('refund_lines')->where('original_order_line_id', $line->id)->sum('amount_cents'))
        ->toBe(1099);
});

it('at a tax-inclusive location, a refund is the gross line total — never gross plus the tax already embedded in it', function (): void {
    $location = provisionedLocation(['prices_include_tax' => true]);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);
    $supervisor = staffWithRole($location, Roles::SUPERVISOR);
    Shift::factory()->create(['register_id' => $register->id]);

    $taxRate = TaxRate::factory()->create(['rate_micros' => 200_000]);   // 20% VAT
    $variant = ProductVariant::factory()->create([
        'price_cents' => 1000, 'track_inventory' => true, 'tax_rate_id' => $taxRate->id,
    ]);
    DB::transaction(fn () => app(StockLedger::class)->receive($variant->id, $location->id, Quantity::fromString('10')));

    $order = Order::factory()->forRegister($register)
        ->create(['opened_by' => $cashier->id, 'prices_include_tax' => true]);
    $order = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $order->id, variantId: $variant->id, qty: '3',
        expectedVersion: Order::findOrFail($order->id)->version, actorId: $cashier->id, registerId: $register->id,
    ))->order;
    $line = $order->lines->first();

    // gross line = 1000 x 3 = 3000; tax embedded = round(3000 x 0.2 / 1.2) = 500. The
    // shelf price already contains the tax, so the order total is the gross subtotal —
    // tax_cents is only the extracted portion, not an amount still owed on top.
    expect($line->line_total_cents)->toBe(3000)
        ->and($line->tax_cents)->toBe(500);

    $order = app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $order->id, registerId: $register->id, driver: 'cash',
        amountCents: $order->total_cents, tenderedCents: $order->total_cents, reference: null,
        expectedVersion: Order::findOrFail($order->id)->version, actorId: $cashier->id,
    ))->order;
    expect($order->total_cents)->toBe(3000);

    $refund = app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $order->id, registerId: $register->id, driver: 'cash',
        reason: 'Customer return', lines: [new RefundLineInput($line->id, '3', restock: true)],
        actorId: $supervisor->id,
    ));

    // The gross line total — not 3000 + 500, which would refund the VAT twice.
    expect($refund->amount_cents)->toBe(3000);

    // Same location, a fresh order, refunding only 1 of 3 units: the prorated fraction
    // must be taken of the gross (3000), never of a re-inflated (gross + tax) figure.
    $partialOrder = Order::factory()->forRegister($register)
        ->create(['opened_by' => $cashier->id, 'prices_include_tax' => true]);
    $partialOrder = app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $partialOrder->id, variantId: $variant->id, qty: '3',
        expectedVersion: Order::findOrFail($partialOrder->id)->version, actorId: $cashier->id, registerId: $register->id,
    ))->order;
    $partialLine = $partialOrder->lines->first();
    $partialOrder = app(TakePayment::class)->execute(new TakePaymentInput(
        orderId: $partialOrder->id, registerId: $register->id, driver: 'cash',
        amountCents: $partialOrder->total_cents, tenderedCents: $partialOrder->total_cents, reference: null,
        expectedVersion: Order::findOrFail($partialOrder->id)->version, actorId: $cashier->id,
    ))->order;

    $partialRefund = app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $partialOrder->id, registerId: $register->id, driver: 'cash',
        reason: 'Customer return', lines: [new RefundLineInput($partialLine->id, '1', restock: true)],
        actorId: $supervisor->id,
    ));

    expect($partialRefund->amount_cents)->toBe(1000);
});

it("a cash refund lowers the shift's expected cash by exactly the refund amount", function (): void {
    $before = app(ShiftTotals::class)->expectedCashCents($this->shift);
    expect($before)->toBe($this->shift->opening_float_cents + 2000);   // float + the sale

    t8Refund($this, [new RefundLineInput($this->line->id, '2', restock: true)]);

    $after = app(ShiftTotals::class)->expectedCashCents($this->shift);
    expect($after)->toBe($this->shift->opening_float_cents + 2000 - 2000);
});

it('refuses to refund an order that is still open', function (): void {
    $openOrder = Order::factory()->forRegister($this->register)->create(['opened_by' => $this->cashier->id]);
    $openOrder = t8AddLine($this, $openOrder->id, $this->variant->id, '1');
    $line = $openOrder->lines->first();

    expect(fn () => app(RefundOrder::class)->execute(new RefundOrderInput(
        originalOrderId: $openOrder->id, registerId: $this->register->id, driver: 'cash',
        reason: 'x', lines: [new RefundLineInput($line->id, '1', restock: true)],
        actorId: $this->supervisor->id,
    )))->toThrow(OrderClosed::class);
});

it('is denied to a cashier and allowed to a supervisor over HTTP', function (): void {
    $url = '/api/v1/refunds';
    $body = [
        'original_order_id' => $this->order->id,
        'driver' => 'cash',
        'reason' => 'Customer return',
        'lines' => [['original_order_line_id' => $this->line->id, 'qty' => '2', 'restock' => true]],
    ];

    $this->postJson($url, $body, staffHeaders($this->register, $this->cashier)
        + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(403);

    $this->postJson($url, $body, staffHeaders($this->register, $this->supervisor)
        + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('data.refund.amount_cents', 2000)
        ->assertJsonPath('data.refund.original_order_id', $this->order->id);
});

it('requires the Idempotency-Key header', function (): void {
    $body = [
        'original_order_id' => $this->order->id,
        'driver' => 'cash',
        'reason' => 'Customer return',
        'lines' => [['original_order_line_id' => $this->line->id, 'qty' => '2', 'restock' => true]],
    ];

    $this->postJson('/api/v1/refunds', $body, staffHeaders($this->register, $this->supervisor))
        ->assertStatus(400);
});

it('a replayed idempotency key refunds once', function (): void {
    $headers = staffHeaders($this->register, $this->supervisor) + ['Idempotency-Key' => (string) Str::uuid()];
    $body = [
        'original_order_id' => $this->order->id,
        'driver' => 'cash',
        'reason' => 'Customer return',
        'lines' => [['original_order_line_id' => $this->line->id, 'qty' => '2', 'restock' => true]],
    ];

    $first = $this->postJson('/api/v1/refunds', $body, $headers);
    $second = $this->postJson('/api/v1/refunds', $body, $headers);

    $first->assertCreated()->assertJsonPath('data.refund.amount_cents', 2000);
    // toEqual, not toBe: the replayed body round-trips through the idempotency_keys
    // table's jsonb column, which does not preserve object key order.
    expect($second->json())->toEqual($first->json())
        ->and(Refund::where('original_order_id', $this->order->id)->count())->toBe(1);
});

it('rejects external_card — that money never passed through us', function (): void {
    $body = [
        'original_order_id' => $this->order->id,
        'driver' => 'external_card',
        'reason' => 'Customer return',
        'lines' => [['original_order_line_id' => $this->line->id, 'qty' => '2', 'restock' => true]],
    ];

    $this->postJson('/api/v1/refunds', $body, staffHeaders($this->register, $this->supervisor)
        + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(400);
});
