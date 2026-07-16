<?php

declare(strict_types=1);

use App\Actions\Orders\OpenOrder;
use App\Actions\Orders\OpenOrderInput;
use App\Domain\Rbac\Roles;
use App\Exceptions\Domain\NoOpenShift;
use App\Models\OrderStatus;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'DT', 'timezone' => 'America/New_York', 'prices_include_tax' => false]);
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
});

it('refuses to open an order without an open shift', function (): void {
    expect(fn () => app(OpenOrder::class)->execute(new OpenOrderInput(
        registerId: $this->register->id, actorId: $this->cashier->id, tableRef: null, customerId: null,
    )))->toThrow(NoOpenShift::class);
});

it('opens with number, snapshot, business date, and version 0', function (): void {
    $shift = Shift::factory()->create(['register_id' => $this->register->id]);

    $order = app(OpenOrder::class)->execute(new OpenOrderInput(
        registerId: $this->register->id, actorId: $this->cashier->id, tableRef: '12', customerId: null,
    ));

    $localDate = now('America/New_York')->toDateString();

    expect($order->number)->toBe('DT-'.str_replace('-', '', $localDate).'-0001')
        ->and($order->status)->toBe(OrderStatus::Open)
        ->and($order->version)->toBe(0)
        ->and($order->shift_id)->toBe($shift->id)
        ->and($order->prices_include_tax)->toBeFalse()
        ->and($order->business_date)->toBe($localDate)
        ->and($order->table_ref)->toBe('12');
});

it('opens over HTTP with the envelope', function (): void {
    Shift::factory()->create(['register_id' => $this->register->id]);

    $this->postJson('/api/v1/orders', [], staffHeaders($this->register, $this->cashier))
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.version', 0)
        ->assertJsonPath('data.total_cents', 0);
});
