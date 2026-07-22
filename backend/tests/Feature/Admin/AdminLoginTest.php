<?php
// backend/tests/Feature/Admin/AdminLoginTest.php
declare(strict_types=1);

use App\Models\User;

function adminUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'boss@pos.test',
        'password_hash' => 'secret-password',   // 'hashed' cast bcrypts on set
        'is_admin' => true,
        'is_active' => true,
    ], $attrs));
}

it('logs an admin in and the token works on an admin route', function (): void {
    $admin = adminUser();

    // Pinned explicitly rather than relying on phpunit.xml's POS_CURRENCY=USD: that
    // `<env>` is soft and loses to a real environment variable (e.g. compose.dev.yml's
    // POS_CURRENCY=PHP under `make test-backend`) by design — see the Makefile's own
    // comment on why `-e` overrides beat phpunit.xml. Pinning config() here proves the
    // response mirrors config deterministically, regardless of which env it runs under.
    config(['pos.currency' => 'USD']);

    $response = $this->postJson('/api/v1/admin/login', [
        'email' => 'boss@pos.test', 'password' => 'secret-password',
    ])->assertOk()->assertJsonPath('data.user.is_admin', true)
        // The back office's entry point; it has no catalog fetch of its own, so login is
        // where it learns the server's currency.
        ->assertJsonPath('data.currency', 'USD');

    $token = $response->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty();

    $this->postJson('/api/v1/admin/logout', [], ['Authorization' => "Bearer {$token}"])
        ->assertNoContent();
    // revoked: the same token no longer authenticates
    $this->postJson('/api/v1/admin/logout', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);

    $this->assertDatabaseHas('audit_log', ['action' => 'admin.login', 'entity_id' => $admin->id]);
});

it('refuses wrong password, unknown email, inactive, and non-admin identically', function (): void {
    adminUser();
    adminUser(['email' => 'inactive@pos.test', 'is_active' => false]);
    adminUser(['email' => 'cashier@pos.test', 'is_admin' => false]);

    foreach ([
        ['email' => 'boss@pos.test', 'password' => 'wrong'],
        ['email' => 'nobody@pos.test', 'password' => 'secret-password'],
        ['email' => 'inactive@pos.test', 'password' => 'secret-password'],
        ['email' => 'cashier@pos.test', 'password' => 'secret-password'],
    ] as $attempt) {
        $this->postJson('/api/v1/admin/login', $attempt)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }
});

it('throttles the login route', function (): void {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/admin/login', ['email' => 'x@pos.test', 'password' => 'x']);
    }
    $this->postJson('/api/v1/admin/login', ['email' => 'x@pos.test', 'password' => 'x'])
        ->assertStatus(429);
});

it('EnsureBackOffice refuses a non-admin bearer token with 403', function (): void {
    $staff = User::factory()->create(['email' => 'sup@pos.test', 'password_hash' => 'pw', 'is_admin' => false]);
    $token = $staff->createToken('test')->plainTextToken;
    $this->postJson('/api/v1/admin/logout', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});
