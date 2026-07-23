<?php
// backend/tests/Feature/Admin/Day/BusinessDayHttpTest.php
declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\CloseShiftInput;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\OpenShiftInput;
use App\Domain\Rbac\PermissionAssignments;
use App\Domain\Rbac\Permissions;
use App\Domain\Rbac\Roles;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function dayHttpFixture(): array
{
    $location = provisionedLocation(['code' => 'HT', 'timezone' => 'Asia/Manila']);
    $register = registerAt($location);
    $cashier = staffWithRole($location, Roles::CASHIER);

    $admin = User::factory()->create(['email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('pw'), 'is_admin' => true]);
    $adminHeaders = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    return [$location, $register, $cashier, $adminHeaders];
}

it('closes a day over HTTP once every shift is closed', function (): void {
    [$location, $register, $cashier, $adminHeaders] = dayHttpFixture();
    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 10000, note: null, actorId: $cashier->id,
    ));

    $this->postJson("/api/v1/admin/locations/{$location->id}/day/close", [
        'deposit_cents' => 5000,
        'checklist' => ['cash_drop_confirmed' => true, 'spoilage_note' => '', 'next_day_note' => ''],
        'note' => null,
    ], $adminHeaders)->assertOk()->assertJsonPath('data.shift_count', 1);
});

it('rejects closing a future-dated day with a validation error', function (): void {
    [$location, , , $adminHeaders] = dayHttpFixture();
    $future = now($location->timezone)->addDay()->toDateString();

    $this->postJson("/api/v1/admin/locations/{$location->id}/day/close", [
        'date' => $future, 'deposit_cents' => 0,
        'checklist' => ['cash_drop_confirmed' => true, 'spoilage_note' => '', 'next_day_note' => ''],
        'note' => null,
    ], $adminHeaders)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a checklist with an unknown key', function (): void {
    [$location, , , $adminHeaders] = dayHttpFixture();

    $this->postJson("/api/v1/admin/locations/{$location->id}/day/close", [
        'deposit_cents' => 0,
        'checklist' => ['cash_drop_confirmed' => true, 'bogus_key' => 'x'],
        'note' => null,
    ], $adminHeaders)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('blocks close with an open shift and returns day_has_open_shifts', function (): void {
    [$location, $register, $cashier, $adminHeaders] = dayHttpFixture();
    app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));

    $this->postJson("/api/v1/admin/locations/{$location->id}/day/close", [
        'deposit_cents' => 0, 'checklist' => [], 'note' => null,
    ], $adminHeaders)->assertStatus(409)->assertJsonPath('error.code', 'day_has_open_shifts');
});

it('refuses to re-close an already-closed day and returns day_already_closed', function (): void {
    [$location, $register, $cashier, $adminHeaders] = dayHttpFixture();
    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 10000, note: null, actorId: $cashier->id,
    ));
    $closeBody = [
        'deposit_cents' => 5000,
        'checklist' => ['cash_drop_confirmed' => true, 'spoilage_note' => '', 'next_day_note' => ''],
        'note' => null,
    ];
    $this->postJson("/api/v1/admin/locations/{$location->id}/day/close", $closeBody, $adminHeaders)->assertOk();

    $this->postJson("/api/v1/admin/locations/{$location->id}/day/close", $closeBody, $adminHeaders)
        ->assertStatus(409)->assertJsonPath('error.code', 'day_already_closed');
});

it('denies day.close to a session without the permission', function (): void {
    [$location, , , ] = dayHttpFixture();
    $user = User::factory()->create(['email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('pw')]);
    app(PermissionAssignments::class)->sync($user, [['location_id' => $location->id, 'permission' => Permissions::REPORT_SALES_VIEW]]);
    $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    $this->getJson("/api/v1/admin/locations/{$location->id}/day", $headers)->assertStatus(403);
});

it('lets a non-admin holder read the day but only admins reopen it', function (): void {
    [$location, , , $adminHeaders] = dayHttpFixture();
    $holder = User::factory()->create(['email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('pw')]);
    app(PermissionAssignments::class)->sync($holder, [['location_id' => $location->id, 'permission' => Permissions::DAY_CLOSE]]);
    $holderHeaders = ['Authorization' => 'Bearer '.$holder->createToken('t')->plainTextToken];

    $this->getJson("/api/v1/admin/locations/{$location->id}/day", $holderHeaders)
        ->assertOk()->assertJsonPath('data.location_today', now($location->timezone)->toDateString());
    // reopen is is_admin only — a day.close holder is still refused
    $this->postJson("/api/v1/admin/locations/{$location->id}/day/reopen", ['reason' => 'x'], $holderHeaders)->assertStatus(403);
});

it('lists a location\'s business days newest-first in the standard items envelope', function (): void {
    [$location, $register, $cashier, $adminHeaders] = dayHttpFixture();
    $shift = app(OpenShift::class)->execute(new OpenShiftInput(
        registerId: $register->id, openingFloatCents: 10000, actorId: $cashier->id,
    ));
    app(CloseShift::class)->execute(new CloseShiftInput(
        shiftId: $shift->id, registerId: $register->id, countedCashCents: 10000, note: null, actorId: $cashier->id,
    ));
    $this->postJson("/api/v1/admin/locations/{$location->id}/day/close", [
        'deposit_cents' => 5000,
        'checklist' => ['cash_drop_confirmed' => true, 'spoilage_note' => '', 'next_day_note' => ''],
        'note' => null,
    ], $adminHeaders)->assertOk();

    $response = $this->getJson("/api/v1/admin/locations/{$location->id}/days", $adminHeaders)->assertOk();

    $response->assertJsonPath('data.items.0.location_id', $location->id)
        ->assertJsonPath('data.items.0.shift_count', 1)
        ->assertJsonCount(1, 'data.items');
});

it('scopes day.close to the location it was granted at, not every location', function (): void {
    [$locationA, , , ] = dayHttpFixture();
    $locationB = provisionedLocation(['code' => 'HB', 'timezone' => 'Asia/Manila']);

    $user = User::factory()->create(['email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('pw')]);
    app(PermissionAssignments::class)->sync($user, [['location_id' => $locationA->id, 'permission' => Permissions::DAY_CLOSE]]);
    $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

    // holds day.close at A — reading A's day succeeds
    $this->getJson("/api/v1/admin/locations/{$locationA->id}/day", $headers)->assertOk();

    // does not hold it at B — refused on both read and close
    $this->getJson("/api/v1/admin/locations/{$locationB->id}/day", $headers)->assertStatus(403);
    $this->postJson("/api/v1/admin/locations/{$locationB->id}/day/close", [
        'deposit_cents' => 0,
        'checklist' => ['cash_drop_confirmed' => true, 'spoilage_note' => '', 'next_day_note' => ''],
        'note' => null,
    ], $headers)->assertStatus(403);
});
