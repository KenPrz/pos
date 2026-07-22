<?php

declare(strict_types=1);

use App\Domain\Rbac\RoleProvisioner;
use App\Models\Location;
use App\Models\RoleTemplate;
use Illuminate\Support\Facades\DB;

it('seeds cashier and supervisor as system templates', function (): void {
    app(RoleProvisioner::class)->provisionGlobal();

    $cashier = RoleTemplate::query()->where('name', 'cashier')->firstOrFail();
    expect($cashier->is_system)->toBeTrue();
    expect($cashier->permissions()->pluck('name')->all())->toContain('order.open', 'payment.take');
});

it('materializes every template at a new location, including via the admin API', function (): void {
    $location = provisionedLocation(['code' => 'AA']);
    $admin = \App\Models\User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    $created = $this->postJson('/api/v1/admin/locations', [
        'name' => 'New Store', 'code' => 'NS', 'timezone' => 'Asia/Manila', 'prices_include_tax' => true,
    ], $headers)->assertStatus(201)->json('data.location');

    // The audit's confirmed bug: assigning a role at an API-created location must now work.
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Newhire', 'pin' => '7777',
        'roles' => [['location_id' => $created['id'], 'role' => 'cashier']],
    ], $headers)->assertStatus(201);

    expect(DB::table('roles')->where('location_id', $created['id'])->pluck('name')->sort()->values()->all())
        ->toBe(['cashier', 'supervisor']);
});

it('syncTemplate pushes an edited permission set to every location', function (): void {
    $a = provisionedLocation(['code' => 'TA']);
    $b = provisionedLocation(['code' => 'TB']);
    $template = RoleTemplate::query()->where('name', 'cashier')->firstOrFail();

    $void = \Spatie\Permission\Models\Permission::query()->where('name', 'order.line.void')->firstOrFail();
    $template->permissions()->syncWithoutDetaching([$void->id]);
    app(RoleProvisioner::class)->syncTemplate($template->fresh());

    foreach ([$a, $b] as $location) {
        $roleId = DB::table('roles')->where('name', 'cashier')->where('location_id', $location->id)->value('id');
        $held = DB::table('role_has_permissions')->where('role_id', $roleId)
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->pluck('permissions.name');
        expect($held)->toContain('order.line.void');
    }
});
