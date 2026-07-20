<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Register;
use App\Models\User;

/** Issue a code for the register through the real admin endpoint. */
function issueCodeFor(Register $register): string
{
    $admin = User::factory()->admin()->create();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

    return test()->postJson("/api/v1/admin/registers/{$register->id}/activation-code", [], $headers)
        ->assertCreated()
        ->json('data.activation_code');
}

function freshRegister(): Register
{
    return Register::factory()->create(['location_id' => Location::factory()->create()->id]);
}

it('redeems an activation code for a working device token', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);

    $response = $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])
        ->assertCreated();

    expect($response->json('data.register'))->toEqual([
        'id' => $register->id, 'name' => $register->name, 'mode' => $register->mode,
    ]);

    $token = $response->json('data.device_token');
    $this->getJson('/api/v1/catalog', ['Authorization' => "Bearer {$token}"])->assertOk();

    $this->assertDatabaseHas('audit_log', ['action' => 'register.activate', 'entity_id' => $register->id]);
});

it('accepts the code typed lowercase without the hyphen', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);

    $this->postJson('/api/v1/registers/activate', ['activation_code' => strtolower(str_replace('-', '', $code))])
        ->assertCreated();
});

it('rejects an unknown code', function (): void {
    $this->postJson('/api/v1/registers/activate', ['activation_code' => 'AAAAA-AAAAA'])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_activation_code');
});

it('rejects a second redemption with the same error as an unknown code', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);
    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])->assertCreated();

    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_activation_code');
});

it('rejects an expired code distinctly, so the installer knows to ask for a reissue', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);

    $this->travel(8)->days();

    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])
        ->assertStatus(401)->assertJsonPath('error.code', 'activation_code_expired');
});

it('rejects a code for a deactivated register as invalid, not expired', function (): void {
    $register = freshRegister();
    $code = issueCodeFor($register);
    $register->update(['is_active' => false]);

    $this->postJson('/api/v1/registers/activate', ['activation_code' => $code])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_activation_code');
});

it('a reissue invalidates the previous unredeemed code', function (): void {
    $register = freshRegister();
    $first = issueCodeFor($register);
    $second = issueCodeFor($register);

    $this->postJson('/api/v1/registers/activate', ['activation_code' => $first])
        ->assertStatus(401)->assertJsonPath('error.code', 'invalid_activation_code');
    $this->postJson('/api/v1/registers/activate', ['activation_code' => $second])->assertCreated();
});

it('requires an activation_code in the body', function (): void {
    $this->postJson('/api/v1/registers/activate', [])
        ->assertStatus(400)->assertJsonPath('error.code', 'validation_failed');
});

it('throttles activation attempts by IP', function (): void {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/registers/activate', ['activation_code' => 'AAAAA-AAAA'.$i])
            ->assertStatus(401);
    }

    $this->postJson('/api/v1/registers/activate', ['activation_code' => 'AAAAA-AAAAA'])
        ->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
});
