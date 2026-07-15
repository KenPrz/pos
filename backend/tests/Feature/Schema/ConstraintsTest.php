<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Register;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| "What the schema refuses to allow" from docs/02-data-model.md, proven rather than
| asserted. These run against real Postgres — SQLite silently ignores partial indexes and
| check constraints, so a green SQLite suite would claim these invariants hold when they
| do not. See docs/01-architecture.md.
*/

function openShift(Register $register, User $user): string
{
    $id = (string) Str::uuid7();

    DB::table('shifts')->insert([
        'id' => $id,
        'register_id' => $register->id,
        'opened_by' => $user->id,
        'opening_float_cents' => 20_000,
    ]);

    return $id;
}

beforeEach(function (): void {
    $this->location = Location::factory()->create();
    $this->register = Register::factory()->create(['location_id' => $this->location->id]);
    $this->user = User::factory()->create();
});

it('refuses two open shifts on one register', function (): void {
    // The entire concurrency story for shifts: two cashiers racing to open the same
    // register produce one winner and one constraint violation. No application check,
    // no lock.
    openShift($this->register, $this->user);

    expect(fn () => openShift($this->register, $this->user))
        ->toThrow(QueryException::class, 'one_open_shift_per_register');
});

it('allows a new shift once the previous one is closed', function (): void {
    $first = openShift($this->register, $this->user);

    DB::table('shifts')->where('id', $first)->update([
        'closed_at' => now(),
        'closed_by' => $this->user->id,
        'counted_cash_cents' => 20_000,
    ]);

    expect(fn () => openShift($this->register, $this->user))->not->toThrow(QueryException::class);
});

it('allows two registers to have open shifts at once', function (): void {
    $other = Register::factory()->create(['location_id' => $this->location->id]);

    openShift($this->register, $this->user);

    expect(fn () => openShift($other, $this->user))->not->toThrow(QueryException::class);
});

it('refuses to close a drawer without counting it', function (): void {
    // "Closed" and "counted" are inseparable at the schema level.
    $shift = openShift($this->register, $this->user);

    expect(fn () => DB::table('shifts')->where('id', $shift)->update([
        'closed_at' => now(),
        'closed_by' => $this->user->id,
        // no counted_cash_cents
    ]))->toThrow(QueryException::class, 'shifts_closed_implies_counted');
});

it('refuses a user who cannot authenticate at all', function (): void {
    expect(fn () => DB::table('users')->insert(['name' => 'Ghost']))
        ->toThrow(QueryException::class, 'users_can_authenticate');
});

it('refuses a percent discount carrying a cash amount', function (): void {
    // Makes the combination unrepresentable rather than merely discouraged.
    expect(fn () => DB::table('discounts')->insert([
        'name' => 'Broken',
        'kind' => 'percent',
        'percent_micros' => 100_000,
        'amount_cents' => 500,
        'scope' => 'order',
    ]))->toThrow(QueryException::class, 'discounts_kind_matches_value');
});

it('refuses a cash movement without a reason', function (): void {
    // An unexplained drawer movement is the most common vector for internal theft.
    $shift = openShift($this->register, $this->user);

    expect(fn () => DB::table('cash_movements')->insert([
        'shift_id' => $shift,
        'kind' => 'payout',
        'amount_cents' => 500,
        'user_id' => $this->user->id,
    ]))->toThrow(QueryException::class);
});

it('refuses a negative cash movement', function (): void {
    // Always positive; `kind` carries the sign. A signed amount would let a typo turn a
    // payout into a paid-in.
    $shift = openShift($this->register, $this->user);

    expect(fn () => DB::table('cash_movements')->insert([
        'shift_id' => $shift,
        'kind' => 'payout',
        'amount_cents' => -500,
        'reason' => 'typo',
        'user_id' => $this->user->id,
    ]))->toThrow(QueryException::class, 'cash_movements_positive');
});

it('refuses a stock movement of zero', function (): void {
    $variant = ProductVariant::factory()->create();

    expect(fn () => DB::table('stock_movements')->insert([
        'variant_id' => $variant->id,
        'location_id' => $this->location->id,
        'qty_delta' => 0,
        'reason' => 'sale',
    ]))->toThrow(QueryException::class, 'stock_movements_non_zero');
});

it('refuses a stock movement with an invented reason', function (): void {
    $variant = ProductVariant::factory()->create();

    expect(fn () => DB::table('stock_movements')->insert([
        'variant_id' => $variant->id,
        'location_id' => $this->location->id,
        'qty_delta' => -1,
        'reason' => 'shrinkage',   // not in the enumerated set
    ]))->toThrow(QueryException::class, 'stock_movements_reason');
});

it('refuses a payment whose change does not balance', function (): void {
    // applied + change = tendered, enforced by the schema rather than by trust.
    $shift = openShift($this->register, $this->user);

    $order = DB::table('orders')->insertGetId([
        'id' => (string) Str::uuid7(),
        'number' => 'DT-20260716-0001',
        'location_id' => $this->location->id,
        'register_id' => $this->register->id,
        'shift_id' => $shift,
        'business_date' => '2026-07-16',
        'opened_by' => $this->user->id,
        'prices_include_tax' => false,
        'total_cents' => 5000,
    ], 'id');

    expect(fn () => DB::table('payments')->insert([
        'order_id' => $order,
        'shift_id' => $shift,
        'driver' => 'cash',
        'status' => 'captured',
        'amount_cents' => 5000,
        'tendered_cents' => 6000,
        'change_cents' => 500,      // should be 1000
        'user_id' => $this->user->id,
    ]))->toThrow(QueryException::class, 'payments_change_balances');
});

/*
| Note for anyone adding to this file: in Postgres a constraint violation aborts the
| enclosing transaction, and RefreshDatabase wraps each test in one. So a test may
| provoke a violation, but nothing can follow it — which is why these two are separate
| rather than one test doing both halves.
*/

it('refuses a duplicate SKU while the original still exists', function (): void {
    ProductVariant::factory()->create(['sku' => 'SKU-REUSE']);

    expect(fn () => ProductVariant::factory()->create(['sku' => 'SKU-REUSE']))
        ->toThrow(QueryException::class, 'variants_sku_unique');
});

it('lets a retired SKU be reissued', function (): void {
    // The unique indexes are partial on deleted_at, so a retired SKU's number is free
    // again rather than blocked forever by a row nobody can see.
    ProductVariant::factory()->create(['sku' => 'SKU-REUSE'])->delete();

    expect(ProductVariant::factory()->create(['sku' => 'SKU-REUSE'])->sku)->toBe('SKU-REUSE');
});

it('generates time-ordered uuidv7 keys in the database', function (): void {
    // Native in Postgres 18 — no extension, no application-side generation for raw SQL.
    DB::table('categories')->insert(['name' => 'Raw insert, no id supplied']);

    $id = DB::table('categories')->where('name', 'Raw insert, no id supplied')->value('id');

    expect($id)->not->toBeNull();

    // Version nibble is 7.
    expect($id[14])->toBe('7');

    $extracted = DB::selectOne('select uuid_extract_timestamp(?::uuid) as ts', [$id]);
    expect(strtotime($extracted->ts))->toBeGreaterThan(time() - 60);
});
