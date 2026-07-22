<?php

// backend/tests/Feature/Admin/SettingsTest.php
declare(strict_types=1);

use App\Actions\Orders\AddLineInput;
use App\Actions\Orders\AddLineToOrder;
use App\Domain\Rbac\Roles;
use App\Domain\Settings\Settings;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;

beforeEach(function (): void {
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

it('falls back to config until a value is set, then prefers the database', function (): void {
    config(['pos.business.name' => 'Env Trading Co']);
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    $before = $this->getJson('/api/v1/admin/settings', $headers)->assertOk()->json('data.settings');
    expect(collect($before)->firstWhere('key', 'business.name'))->toMatchArray(['value' => 'Env Trading Co', 'source' => 'config']);

    $this->patchJson('/api/v1/admin/settings', ['settings' => ['business.name' => 'Manila Trading']], $headers)->assertOk();
    $after = $this->getJson('/api/v1/admin/settings', $headers)->json('data.settings');
    expect(collect($after)->firstWhere('key', 'business.name'))->toMatchArray(['value' => 'Manila Trading', 'source' => 'db']);
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.settings.update']);
});

it('rejects unregistered keys', function (): void {
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    $this->patchJson('/api/v1/admin/settings', ['settings' => ['pos.currency' => 'USD']], $headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('a non-admin token gets 403 on settings routes', function (): void {
    $staff = User::factory()->create(['email' => 's2@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $headers = ['Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken];
    $this->getJson('/api/v1/admin/settings', $headers)->assertStatus(403);
});

it('reflects a database-set business name on the receipt', function (): void {
    $location = provisionedLocation([
        'receipt_header' => 'Downtown Store', 'receipt_footer' => 'Thanks!', 'prices_include_tax' => false,
    ]);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);
    $order = Order::factory()->forRegister($register)->create(['opened_by' => $cashier->id]);
    $variant = ProductVariant::factory()->untracked()->create(['price_cents' => 1999]);

    app(AddLineToOrder::class)->execute(new AddLineInput(
        orderId: $order->id, registerId: $register->id, variantId: $variant->id, qty: '1',
        expectedVersion: 0, actorId: $cashier->id,
    ));

    app(Settings::class)->set('business.name', 'Manila Trading');

    $receipt = $this->getJson("/api/v1/orders/{$order->id}/receipt", staffHeaders($register, $cashier))
        ->assertOk()->json();

    expect($receipt['data']['business']['name'])->toBe('Manila Trading');
});
