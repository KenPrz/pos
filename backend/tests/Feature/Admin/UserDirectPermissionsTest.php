<?php

declare(strict_types=1);

use App\Domain\Rbac\PermissionAssignments;
use App\Domain\Rbac\Permissions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->a = provisionedLocation(['code' => 'PA']);
    $this->b = provisionedLocation(['code' => 'PB']);
    $this->admin = User::factory()->admin()->create();
    $this->headers = ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken];
});

it('grants, lists, and replaces direct permissions per location', function (): void {
    $user = $this->postJson('/api/v1/admin/users', [
        'name' => 'Grantee', 'pin' => '6161',
        'roles' => [['location_id' => $this->a->id, 'role' => 'cashier']],
        'permissions' => [['location_id' => $this->a->id, 'permission' => 'order.discount.apply']],
    ], $this->headers)->assertStatus(201)->json('data.user');

    expect($user['permissions'])->toHaveCount(1);
    expect($user['permissions'][0]['permission'])->toBe('order.discount.apply');
    $this->assertDatabaseHas('model_has_permissions', ['model_id' => $user['id'], 'location_id' => $this->a->id]);

    // full-set replace: empty array clears
    $this->patchJson("/api/v1/admin/users/{$user['id']}", ['permissions' => []], $this->headers)->assertOk();
    expect(DB::table('model_has_permissions')->where('model_id', $user['id'])->count())->toBe(0);
});

it('unions direct grants with role permissions at the granted location only', function (): void {
    $user = User::factory()->withPin('6262')->create(['name' => 'Union']);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->a->id);
    $user->assignRole('cashier');
    $registrar->setPermissionsTeamId($this->b->id);
    $user->assignRole('cashier');

    app(PermissionAssignments::class)
        ->sync($user, [['location_id' => $this->a->id, 'permission' => Permissions::ORDER_DISCOUNT_APPLY]]);
    $registrar->forgetCachedPermissions();

    $registrar->setPermissionsTeamId($this->a->id);
    expect($user->fresh()->can(Permissions::ORDER_DISCOUNT_APPLY))->toBeTrue();
    $registrar->setPermissionsTeamId($this->b->id);
    expect($user->fresh()->can(Permissions::ORDER_DISCOUNT_APPLY))->toBeFalse();
});

it('rejects unknown permission names and unknown locations', function (): void {
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Bad', 'pin' => '6363',
        'roles' => [], 'permissions' => [['location_id' => $this->a->id, 'permission' => 'nope']],
    ], $this->headers)->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
