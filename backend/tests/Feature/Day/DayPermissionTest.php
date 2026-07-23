<?php
// backend/tests/Feature/Day/DayPermissionTest.php
declare(strict_types=1);

use App\Domain\Rbac\AdminAccess;
use App\Domain\Rbac\PermissionAssignments;
use App\Domain\Rbac\Permissions;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('registers day.close as a catalog permission and a back-office section', function (): void {
    expect(Permissions::all())->toContain('day.close')
        ->and(AdminAccess::SECTIONS)->toContain('day.close');
});

it('grants back-office day.close to a user who holds it anywhere', function (): void {
    $location = provisionedLocation(['code' => 'DP']);
    $user = User::factory()->create(['email' => fake()->unique()->safeEmail(), 'password_hash' => Hash::make('secret-pass')]);
    app(PermissionAssignments::class)->sync($user, [
        ['location_id' => $location->id, 'permission' => Permissions::DAY_CLOSE],
    ]);

    expect(app(AdminAccess::class)->holdsAnywhere($user->refresh(), Permissions::DAY_CLOSE))->toBeTrue()
        ->and(app(AdminAccess::class)->sectionsFor($user))->toContain('day.close');
});
