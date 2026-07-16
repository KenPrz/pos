<?php

declare(strict_types=1);

use App\Actions\Stock\AdjustStock;
use App\Actions\Stock\AdjustStockInput;
use App\Domain\Rbac\Roles;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->variant = ProductVariant::factory()->create();
});

function t11Level(object $t, string $variantId): ?string
{
    return DB::table('stock_levels')
        ->where('variant_id', $variantId)->where('location_id', $t->location->id)
        ->value('qty');
}

it('receives, wastes and counts stock, and the movement history reads it back in order', function (): void {
    $this->postJson('/api/v1/stock/receipts', [
        'variant_id' => $this->variant->id, 'qty' => '10', 'note' => 'delivery',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertCreated()
        ->assertJsonPath('data.level.variant_id', $this->variant->id)
        ->assertJsonPath('data.level.qty', '10.000');

    $this->postJson('/api/v1/stock/adjustments', [
        'variant_id' => $this->variant->id, 'qty_delta' => '-2', 'reason' => 'waste', 'note' => 'dropped tray',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertCreated()
        ->assertJsonPath('data.level.qty', '8.000');

    $this->postJson('/api/v1/stock/counts', [
        'variant_id' => $this->variant->id, 'counted_qty' => '7', 'note' => 'weekly count',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertCreated()
        ->assertJsonPath('data.level.qty', '7.000');

    expect(t11Level($this, $this->variant->id))->toBe('7.000');

    $response = $this->getJson('/api/v1/stock/movements?variant_id='.$this->variant->id, staffHeaders($this->register, $this->supervisor))
        ->assertOk()
        ->assertJsonPath('data.level.qty', '7.000');

    $movements = $response->json('data.movements');
    expect($movements)->toHaveCount(3)
        ->and($movements[0]['reason'])->toBe('count')
        ->and($movements[0]['qty_delta'])->toBe('-1.000')
        ->and($movements[1]['reason'])->toBe('waste')
        ->and($movements[1]['qty_delta'])->toBe('-2.000')
        ->and($movements[2]['reason'])->toBe('receive')
        ->and($movements[2]['qty_delta'])->toBe('10.000');
});

it('refuses to waste below zero — 409 insufficient_stock', function (): void {
    $this->postJson('/api/v1/stock/receipts', [
        'variant_id' => $this->variant->id, 'qty' => '1',
    ], staffHeaders($this->register, $this->supervisor))->assertCreated();

    $this->postJson('/api/v1/stock/adjustments', [
        'variant_id' => $this->variant->id, 'qty_delta' => '-5', 'reason' => 'waste',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'insufficient_stock');

    expect(t11Level($this, $this->variant->id))->toBe('1.000');
});

it('rejects a zero qty_delta adjustment — 400', function (): void {
    $this->postJson('/api/v1/stock/adjustments', [
        'variant_id' => $this->variant->id, 'qty_delta' => '0', 'reason' => 'adjustment',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->postJson('/api/v1/stock/adjustments', [
        'variant_id' => $this->variant->id, 'qty_delta' => '0.000', 'reason' => 'adjustment',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertStatus(400);
});

it('rejects a positive waste delta before it ever reaches the ledger — 400', function (): void {
    $this->postJson('/api/v1/stock/adjustments', [
        'variant_id' => $this->variant->id, 'qty_delta' => '3', 'reason' => 'waste',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('allows counting a shelf down to zero', function (): void {
    $this->postJson('/api/v1/stock/receipts', [
        'variant_id' => $this->variant->id, 'qty' => '4',
    ], staffHeaders($this->register, $this->supervisor))->assertCreated();

    $this->postJson('/api/v1/stock/counts', [
        'variant_id' => $this->variant->id, 'counted_qty' => '0',
    ], staffHeaders($this->register, $this->supervisor))
        ->assertCreated()
        ->assertJsonPath('data.level.qty', '0.000');

    expect(t11Level($this, $this->variant->id))->toBe('0.000');
});

it('writes an audit row for an adjustment', function (): void {
    $this->postJson('/api/v1/stock/adjustments', [
        'variant_id' => $this->variant->id, 'qty_delta' => '5', 'reason' => 'adjustment', 'note' => 'found extras',
    ], staffHeaders($this->register, $this->supervisor))->assertCreated();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'stock.adjust',
        'entity_type' => 'ProductVariant',
        'entity_id' => $this->variant->id,
    ]);
});

it('adjusts stock directly via the action and returns the new level', function (): void {
    $level = app(AdjustStock::class)->execute(new AdjustStockInput(
        variantId: $this->variant->id,
        registerId: $this->register->id,
        qtyDelta: '5',
        reason: 'adjustment',
        note: null,
        actorId: $this->supervisor->id,
    ));

    expect($level->variant_id)->toBe($this->variant->id)
        ->and($level->qty)->toBe('5.000');
});

it('forbids a cashier and allows a supervisor on all four stock routes', function (string $method, string $uri, array $body): void {
    $cashierResponse = $method === 'GET'
        ? $this->getJson($uri, staffHeaders($this->register, $this->cashier))
        : $this->postJson($uri, $body, staffHeaders($this->register, $this->cashier));
    $cashierResponse->assertStatus(403);

    $supervisorResponse = $method === 'GET'
        ? $this->getJson($uri, staffHeaders($this->register, $this->supervisor))
        : $this->postJson($uri, $body, staffHeaders($this->register, $this->supervisor));
    $supervisorResponse->assertSuccessful();
})->with([
    'adjust' => fn () => ['POST', '/api/v1/stock/adjustments', [
        'variant_id' => ProductVariant::factory()->create()->id, 'qty_delta' => '3', 'reason' => 'adjustment',
    ]],
    'receive' => fn () => ['POST', '/api/v1/stock/receipts', [
        'variant_id' => ProductVariant::factory()->create()->id, 'qty' => '3',
    ]],
    'count' => fn () => ['POST', '/api/v1/stock/counts', [
        'variant_id' => ProductVariant::factory()->create()->id, 'counted_qty' => '3',
    ]],
]);

it('forbids a cashier and allows a supervisor on the movements route', function (): void {
    $this->getJson('/api/v1/stock/movements?variant_id='.$this->variant->id, staffHeaders($this->register, $this->cashier))
        ->assertStatus(403);

    $this->getJson('/api/v1/stock/movements?variant_id='.$this->variant->id, staffHeaders($this->register, $this->supervisor))
        ->assertOk();
});
