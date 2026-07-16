<?php

declare(strict_types=1);

use App\Domain\Money\Quantity;
use App\Domain\Stock\StockLedger;
use App\Exceptions\Domain\InsufficientStock;
use App\Models\Location;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->variant = ProductVariant::factory()->create();
    $this->location = Location::factory()->create();
    $this->ledger = app(StockLedger::class);
});

function stockLevel(string $variantId, string $locationId): ?string
{
    return DB::table('stock_levels')
        ->where('variant_id', $variantId)->where('location_id', $locationId)
        ->value('qty');
}

it('receive creates the level row and the ledger entry', function (): void {
    DB::transaction(fn () => $this->ledger->receive($this->variant->id, $this->location->id, Quantity::fromString('20')));

    expect(stockLevel($this->variant->id, $this->location->id))->toBe('20.000');
    $this->assertDatabaseHas('stock_movements', [
        'variant_id' => $this->variant->id,
        'reason' => 'receive',
        'qty_delta' => '20.000',
    ]);
});

it('sell decrements the level and writes a negative movement referencing the line', function (): void {
    $ref = (string) Str::uuid7();
    DB::transaction(function () use ($ref): void {
        $this->ledger->receive($this->variant->id, $this->location->id, Quantity::fromString('5'));
        $this->ledger->sell($this->variant->id, $this->location->id, Quantity::fromString('1.500'), 'order_line', $ref, null);
    });

    expect(stockLevel($this->variant->id, $this->location->id))->toBe('3.500');
    $this->assertDatabaseHas('stock_movements', [
        'reason' => 'sale',
        'qty_delta' => '-1.500',
        'ref_type' => 'order_line',
        'ref_id' => $ref,
    ]);
});

it('refuses to oversell, with the shortfall in the exception', function (): void {
    DB::transaction(fn () => $this->ledger->receive($this->variant->id, $this->location->id, Quantity::fromString('2')));

    expect(fn () => DB::transaction(fn () => $this->ledger->sell(
        $this->variant->id, $this->location->id, Quantity::fromString('3'), 'order_line', (string) Str::uuid7(), null,
    )))->toThrow(InsufficientStock::class);

    expect(stockLevel($this->variant->id, $this->location->id))->toBe('2.000');
});

it('treats a missing level row as zero stock', function (): void {
    expect(fn () => DB::transaction(fn () => $this->ledger->sell(
        $this->variant->id, $this->location->id, Quantity::fromString('1'), 'order_line', (string) Str::uuid7(), null,
    )))->toThrow(InsufficientStock::class);
});
