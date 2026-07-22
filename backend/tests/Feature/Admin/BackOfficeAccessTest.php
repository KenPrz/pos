<?php

declare(strict_types=1);

use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\PermissionAssignments;
use App\Domain\Rbac\Permissions;
use App\Domain\Rbac\Roles;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->a = provisionedLocation(['code' => 'BA']);
    $this->b = provisionedLocation(['code' => 'BB']);
});

function boUser(array $grants): array
{
    $user = User::factory()->create(['email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('secret-pass')]);
    app(PermissionAssignments::class)->sync($user, $grants);
    $token = $user->createToken('t')->plainTextToken;

    return [$user, ['Authorization' => 'Bearer '.$token]];
}

it('logs a non-admin with an admin-tier permission into the back office with scoped sections', function (): void {
    [$user] = boUser([['location_id' => $this->a->id, 'permission' => Permissions::REPORT_SALES_VIEW]]);

    $session = $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => 'secret-pass'])
        ->assertOk()->json('data');
    expect($session['sections'])->toBe(['report.sales.view']);
});

it('rejects login for users with no admin-tier permission, same envelope as bad credentials', function (): void {
    $user = User::factory()->create(['email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('secret-pass')]);
    $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => 'secret-pass'])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
});

it('lets a report-only user read reports at the granted location and nothing else', function (): void {
    [, $headers] = boUser([['location_id' => $this->a->id, 'permission' => Permissions::REPORT_SALES_VIEW]]);

    $this->getJson("/api/v1/admin/reports/sales?location_id={$this->a->id}&from=2026-07-01&to=2026-07-02&group_by=day", $headers)->assertOk();
    $this->getJson("/api/v1/admin/reports/sales?location_id={$this->b->id}&from=2026-07-01&to=2026-07-02&group_by=day", $headers)->assertStatus(403);
    $this->getJson('/api/v1/admin/users', $headers)->assertStatus(403);
    $this->postJson('/api/v1/admin/categories', ['name' => 'X'], $headers)->assertStatus(403);
});

it('keeps full access for is_admin', function (): void {
    // UserFactory::admin() only flips is_admin — password_hash still comes from
    // definition() (Hash::make('password')), so login is exercised with that password
    // rather than the brief's placeholder 'admin-dev-password'.
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    $this->getJson('/api/v1/admin/users', $headers)->assertOk();

    $session = $this->postJson('/api/v1/admin/login', ['email' => $admin->email, 'password' => 'password'])
        ->assertOk()->json('data');
    expect($session['sections'])->toContain('catalog.manage', 'role.manage');
    expect(app(AdminAccess::class)->sectionsFor($admin))->toContain('catalog.manage', 'role.manage');
});

it('refuses a register staff-session token on the back office, even though a supervisor holds report.sales.view by default', function (): void {
    $register = registerAt($this->a);
    $supervisor = staffWithRole($this->a, Roles::SUPERVISOR);

    // Mirrors StaffLogin::execute's createToken call exactly: same name/ability shape
    // (`staff:{register}` / `register:{register}`), just built directly rather than via
    // the PIN-login endpoint, since we only need the resulting token here.
    $token = $supervisor->createToken(
        "staff:{$register->id}",
        ["register:{$register->id}"],
        Carbon::now()->addMinutes(15),
    )->plainTextToken;

    expect(app(AdminAccess::class)->sectionsFor($supervisor))->toContain(Permissions::REPORT_SALES_VIEW);

    $this->getJson(
        "/api/v1/admin/reports/sales?location_id={$this->a->id}&from=2026-07-01&to=2026-07-02&group_by=day",
        ['Authorization' => "Bearer {$token}"],
    )->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('lets that same supervisor onto the same endpoint once they actually go through admin login', function (): void {
    $supervisor = staffWithRole($this->a, Roles::SUPERVISOR);

    $token = $this->postJson('/api/v1/admin/login', ['email' => $supervisor->email, 'password' => 'password'])
        ->assertOk()->json('data.token');

    $this->getJson(
        "/api/v1/admin/reports/sales?location_id={$this->a->id}&from=2026-07-01&to=2026-07-02&group_by=day",
        ['Authorization' => "Bearer {$token}"],
    )->assertOk();
});
