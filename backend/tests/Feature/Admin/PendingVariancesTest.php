<?php

declare(strict_types=1);

use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\Permissions;
use App\Models\Shift;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

it('registers shift.approve_variance as a back-office section', function (): void {
    // Pins the SECTIONS entry itself: allowsBackOffice/holdsAnywhere check the permission
    // directly, so deleting this from SECTIONS leaves the endpoint fully working and every
    // other test green while sectionsFor() silently stops returning it — the sidebar item
    // disappears for every non-admin, the entire intended audience. Same guard as
    // PaymentMethodPermissionTest and DayPermissionTest for their own sections.
    expect(AdminAccess::SECTIONS)->toContain(Permissions::SHIFT_APPROVE_VARIANCE);
});

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'AAA', 'variance_approval_threshold_cents' => 500]);
    $this->register = registerAt($this->location);
    $admin = User::factory()->create(['email' => 'a@pos.test', 'password_hash' => 'pw', 'is_admin' => true]);
    $this->headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
});

/** A closed shift with a chosen variance. Closing is faked at the row level on purpose:
 *  this action reads the ledger, and driving CloseShift would drag in orders and tenders
 *  that say nothing about the query under test. */
function pv(object $t, int $varianceCents, array $overrides = []): Shift
{
    $shift = Shift::factory()->create(['register_id' => $t->register->id]);
    $shift->forceFill(array_merge([
        'closed_at' => now(),
        'counted_cash_cents' => 10000 + $varianceCents,
        'expected_cash_cents' => 10000,
        'variance_cents' => $varianceCents,
    ], $overrides))->save();

    return $shift->refresh();
}

it('lists a closed, over-threshold, unapproved variance', function (): void {
    $shift = pv($this, -1200);

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.items.0.shift_id', $shift->id)
        ->assertJsonPath('data.items.0.variance_cents', -1200)
        ->assertJsonPath('data.items.0.threshold_cents', 500)
        ->assertJsonPath('data.items.0.register_name', $this->register->name)
        ->assertJsonPath('data.items.0.location_name', $this->location->name);
});

it('excludes an open shift', function (): void {
    // An open shift has no count yet, so there is nothing to approve.
    Shift::factory()->create(['register_id' => $this->register->id]);

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()->assertJsonCount(0, 'data.items');
});

it('excludes a variance at or under the threshold', function (): void {
    // Strictly greater than, matching ApproveVariance's `abs(...) <= threshold` rejection:
    // listing an exactly-at-threshold variance would offer an approval the API refuses
    // with 422 variance_approval_not_required.
    pv($this, 500);
    pv($this, -500);
    pv($this, 100);

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()->assertJsonCount(0, 'data.items');
});

it('excludes an already-approved variance', function (): void {
    pv($this, -1200, ['variance_approved_at' => now(), 'variance_approved_by' => User::factory()->create()->id]);

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()->assertJsonCount(0, 'data.items');
});

it('falls back to the config threshold when the location sets none', function (): void {
    config(['pos.shifts.variance_approval_threshold_cents' => 2000]);
    $this->location->forceFill(['variance_approval_threshold_cents' => null])->save();

    pv($this, 1500);            // under the config default — excluded
    $over = pv($this, 2500);    // over it — listed

    $this->getJson('/api/v1/admin/variances', $this->headers)
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.shift_id', $over->id)
        ->assertJsonPath('data.items.0.threshold_cents', 2000);
});

it('orders the most recently closed first', function (): void {
    $older = pv($this, -900, ['closed_at' => now()->subHours(3)]);
    $newer = pv($this, -900, ['closed_at' => now()]);

    $items = $this->getJson('/api/v1/admin/variances', $this->headers)->assertOk()->json('data.items');
    expect(array_column($items, 'shift_id'))->toBe([$newer->id, $older->id]);
});

it('scopes a non-admin holder to the locations they hold the permission at', function (): void {
    $other = provisionedLocation(['code' => 'BBB', 'variance_approval_threshold_cents' => 500]);
    $otherRegister = registerAt($other);
    $otherShift = Shift::factory()->create(['register_id' => $otherRegister->id]);
    $otherShift->forceFill([
        'closed_at' => now(), 'counted_cash_cents' => 8800,
        'expected_cash_cents' => 10000, 'variance_cents' => -1200,
    ])->save();

    $mine = pv($this, -1200);

    $supervisor = User::factory()->create(['email' => 's@pos.test', 'password_hash' => 'pw']);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->location->id);
    $supervisor->givePermissionTo(Permissions::SHIFT_APPROVE_VARIANCE);
    $registrar->forgetCachedPermissions();

    $items = $this->getJson('/api/v1/admin/variances', [
        'Authorization' => 'Bearer '.$supervisor->createToken('t')->plainTextToken,
    ])->assertOk()->json('data.items');

    expect(array_column($items, 'shift_id'))->toBe([$mine->id]);
});

it('refuses a session without the permission', function (): void {
    $nobody = User::factory()->create(['email' => 'n@pos.test', 'password_hash' => 'pw']);

    $this->getJson('/api/v1/admin/variances', [
        'Authorization' => 'Bearer '.$nobody->createToken('t')->plainTextToken,
    ])->assertStatus(403);
});
