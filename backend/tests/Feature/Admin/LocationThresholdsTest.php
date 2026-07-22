<?php

// backend/tests/Feature/Admin/LocationThresholdsTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\ProductVariant;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('uses a location variance threshold override for approval requirement', function (): void {
    // Built on CloseShiftTest's fixtures (location, register, staff, open shift), with the
    // location's own variance threshold set well above the 700-cent variance we'll close with.
    $location = provisionedLocation(['variance_approval_threshold_cents' => 10_000]);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);
    $shift = Shift::factory()->create([
        'register_id' => $register->id,
        'opened_by' => $cashier->id,
        'opening_float_cents' => 10000,
    ]);

    $headers = staffHeaders($register, $cashier) + ['Idempotency-Key' => (string) Str::uuid()];

    // No orders on the shift, so expected_cash_cents is just the opening float (10000);
    // counting 10700 yields a variance of +700. The config default (500) would require
    // approval; the location override (10000) must not.
    $this->postJson("/api/v1/shifts/{$shift->id}/close", ['counted_cash_cents' => 10700], $headers)
        ->assertOk()
        ->assertJsonPath('data.variance_cents', 700)
        ->assertJsonPath('data.requires_approval', false);
});

it('flags low stock per the location override', function (): void {
    // Built on the ReportsTest stock fixtures: a provisioned location, an admin token, and
    // stock_levels rows inserted directly.
    config(['pos.stock.low_threshold' => 5]);

    $location = provisionedLocation(['low_stock_threshold' => 50]);
    $sibling = provisionedLocation(); // no override -> falls back to the config default (5)

    $admin = User::factory()->create(['email' => 'admin@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    $variant = ProductVariant::factory()->create(['sku' => 'OVR-1', 'track_inventory' => true]);
    DB::table('stock_levels')->insert(['variant_id' => $variant->id, 'location_id' => $location->id, 'qty' => 20, 'updated_at' => now()]);

    $siblingVariant = ProductVariant::factory()->create(['sku' => 'OVR-2', 'track_inventory' => true]);
    DB::table('stock_levels')->insert(['variant_id' => $siblingVariant->id, 'location_id' => $sibling->id, 'qty' => 20, 'updated_at' => now()]);

    // qty 20 against the location's own 50-unit threshold: low.
    $rows = $this->getJson("/api/v1/admin/reports/stock?location_id={$location->id}", $headers)
        ->assertOk()->json('data.rows');
    expect(collect($rows)->firstWhere('sku', 'OVR-1')['low'])->toBeTrue();

    // Same qty 20, but the sibling location has no override -> compares against the
    // config default of 5 instead, and is not low.
    $siblingRows = $this->getJson("/api/v1/admin/reports/stock?location_id={$sibling->id}", $headers)
        ->assertOk()->json('data.rows');
    expect(collect($siblingRows)->firstWhere('sku', 'OVR-2')['low'])->toBeFalse();
});

it('accepts, persists, and returns the two fields through the admin API', function (): void {
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    $loc = $this->postJson('/api/v1/admin/locations', [
        'name' => 'T', 'code' => 'TH', 'timezone' => 'Asia/Manila', 'prices_include_tax' => true,
        'variance_approval_threshold_cents' => 10000, 'low_stock_threshold' => '50',
    ], $headers)->assertStatus(201)->json('data.location');
    expect($loc['variance_approval_threshold_cents'])->toBe(10000);

    $this->patchJson("/api/v1/admin/locations/{$loc['id']}", ['variance_approval_threshold_cents' => null], $headers)->assertOk();
});
