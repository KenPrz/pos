<?php

declare(strict_types=1);

use App\Models\RoleTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->location = provisionedLocation(['code' => 'RC']);
    $this->admin = User::factory()->admin()->create();
    $this->headers = ['Authorization' => 'Bearer '.$this->admin->createToken('t')->plainTextToken];
});

it('lists templates with permissions and assignment counts', function (): void {
    $res = $this->getJson('/api/v1/admin/roles', $this->headers)->assertOk()->json('data.items');
    expect(collect($res)->pluck('name'))->toContain('cashier', 'supervisor');
    expect(collect($res)->firstWhere('name', 'cashier')['permissions'])->toContain('order.open');
});

it('creates a custom template and materializes it at every location', function (): void {
    $res = $this->postJson('/api/v1/admin/roles', [
        'name' => 'shift-lead', 'permissions' => ['order.open', 'order.line.add', 'shift.approve_variance'],
    ], $this->headers)->assertStatus(201)->json('data.role');

    expect($res['is_system'])->toBeFalse();
    expect(DB::table('roles')->where('name', 'shift-lead')->where('location_id', $this->location->id)->exists())->toBeTrue();
    $this->assertDatabaseHas('audit_log', ['action' => 'admin.role.create', 'entity_id' => $res['id']]);

    // assignable immediately
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Lead', 'pin' => '8888',
        'roles' => [['location_id' => $this->location->id, 'role' => 'shift-lead']],
    ], $this->headers)->assertStatus(201);
});

it('edits a permission set and syncs the materialized rows', function (): void {
    $cashier = RoleTemplate::query()->where('name', 'cashier')->firstOrFail();
    $this->patchJson("/api/v1/admin/roles/{$cashier->id}", [
        'permissions' => ['order.open', 'order.line.add'],
    ], $this->headers)->assertOk();

    $roleId = DB::table('roles')->where('name', 'cashier')->where('location_id', $this->location->id)->value('id');
    $held = DB::table('role_has_permissions')->where('role_id', $roleId)
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->pluck('permissions.name');
    expect($held->sort()->values()->all())->toBe(['order.line.add', 'order.open']);
});

it('refuses to rename or delete a system template', function (): void {
    $cashier = RoleTemplate::query()->where('name', 'cashier')->firstOrFail();
    $this->patchJson("/api/v1/admin/roles/{$cashier->id}", ['name' => 'checkout'], $this->headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'role_template_is_system');
    $this->postJson("/api/v1/admin/roles/{$cashier->id}/delete", [], $this->headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'role_template_is_system');
});

it('refuses to delete a template that is assigned somewhere', function (): void {
    $this->postJson('/api/v1/admin/roles', ['name' => 'temp', 'permissions' => ['order.open']], $this->headers);
    $template = RoleTemplate::query()->where('name', 'temp')->firstOrFail();
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Holder', 'pin' => '9191',
        'roles' => [['location_id' => $this->location->id, 'role' => 'temp']],
    ], $this->headers)->assertStatus(201);

    $this->postJson("/api/v1/admin/roles/{$template->id}/delete", [], $this->headers)
        ->assertStatus(422)->assertJsonPath('error.code', 'role_template_in_use');
});

it('deletes an unassigned custom template everywhere', function (): void {
    $this->postJson('/api/v1/admin/roles', ['name' => 'ghost', 'permissions' => ['order.open']], $this->headers);
    $template = RoleTemplate::query()->where('name', 'ghost')->firstOrFail();
    $this->postJson("/api/v1/admin/roles/{$template->id}/delete", [], $this->headers)->assertOk();
    expect(RoleTemplate::query()->where('name', 'ghost')->exists())->toBeFalse();
    expect(DB::table('roles')->where('name', 'ghost')->exists())->toBeFalse();
});

it('serves the grouped permission catalog', function (): void {
    $groups = $this->getJson('/api/v1/admin/permissions', $this->headers)->assertOk()->json('data.groups');
    expect(collect($groups)->pluck('label'))->toContain('Orders', 'Reports');
});

it('rejects unknown permission names', function (): void {
    $this->postJson('/api/v1/admin/roles', ['name' => 'bad', 'permissions' => ['not.a.permission']], $this->headers)
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});
