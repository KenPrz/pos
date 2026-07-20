<?php
// backend/tests/Feature/Drawer/NoSaleTest.php
declare(strict_types=1);

use App\Domain\Rbac\Roles;
use App\Models\Shift;

beforeEach(function (): void {
    $this->location = provisionedLocation();
    $this->register = registerAt($this->location);
    $this->cashier = staffWithRole($this->location, Roles::CASHIER);
    $this->supervisor = staffWithRole($this->location, Roles::SUPERVISOR);
    $this->shift = Shift::factory()->create([
        'register_id' => $this->register->id,
        'opened_by' => $this->cashier->id,
        'opening_float_cents' => 10000,
    ]);
});

it('authorizes a supervisor and writes exactly one audit row', function (): void {
    $this->postJson('/api/v1/drawer/no-sale', ['reason' => 'Change for a twenty'],
        staffHeaders($this->register, $this->supervisor))
        ->assertOk()
        ->assertJsonPath('data.authorized', true)
        ->assertJsonPath('data.shift_id', $this->shift->id);

    $rows = DB::table('audit_log')
        ->where('action', 'drawer.no_sale')
        ->where('entity_id', $this->shift->id)
        ->get();

    expect($rows)->toHaveCount(1);
    expect(json_decode((string) $rows->first()->payload, true))
        ->toBe(['reason' => 'Change for a twenty']);
    expect($rows->first()->user_id)->toBe($this->supervisor->id);
    expect($rows->first()->register_id)->toBe($this->register->id);
});

it('refuses a cashier — the permission is supervisor-only', function (): void {
    $this->postJson('/api/v1/drawer/no-sale', ['reason' => 'Change for a twenty'],
        staffHeaders($this->register, $this->cashier))
        ->assertStatus(403);

    $this->assertDatabaseMissing('audit_log', ['action' => 'drawer.no_sale']);
});

it('requires a reason — an unexplained drawer opening is the whole thing we are preventing', function (): void {
    $this->postJson('/api/v1/drawer/no-sale', ['reason' => ''],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(400);

    $this->assertDatabaseMissing('audit_log', ['action' => 'drawer.no_sale']);
});

it('refuses when no shift is open on this register', function (): void {
    $this->shift->forceFill(['closed_at' => now(), 'closed_by' => $this->cashier->id,
        'counted_cash_cents' => 0, 'expected_cash_cents' => 0, 'variance_cents' => 0])->save();

    $this->postJson('/api/v1/drawer/no-sale', ['reason' => 'Change for a twenty'],
        staffHeaders($this->register, $this->supervisor))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'no_open_shift');
});
