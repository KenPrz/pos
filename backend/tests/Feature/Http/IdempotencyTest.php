<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // A minimal idempotent endpoint: every *executed* call inserts an audit_log row
    // (a handy pre-existing table). Replays must not add rows.
    Route::post('/api/v1/_test/idempotent', function () {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'action' => 'test.executed',
            'entity_type' => 'test',
            'created_at' => now(),
        ]);

        return response()->json(['data' => ['ok' => true]], 201);
    })->middleware('idempotent');

    Route::post('/api/v1/_test/failing', function () {
        return response()->json(['error' => ['code' => 'nope', 'message' => '', 'details' => []]], 409);
    })->middleware('idempotent');
});

function executedCount(): int
{
    return DB::table('audit_log')->where('action', 'test.executed')->count();
}

it('passes through without a key', function (): void {
    $this->postJson('/api/v1/_test/idempotent', ['a' => 1])->assertCreated();
    $this->postJson('/api/v1/_test/idempotent', ['a' => 1])->assertCreated();

    expect(executedCount())->toBe(2);
});

it('replays the stored response without re-executing', function (): void {
    $key = (string) Str::uuid();

    $first = $this->postJson('/api/v1/_test/idempotent', ['a' => 1], ['Idempotency-Key' => $key]);
    $second = $this->postJson('/api/v1/_test/idempotent', ['a' => 1], ['Idempotency-Key' => $key]);

    $first->assertCreated();
    $second->assertStatus(201);
    expect($second->json())->toBe($first->json())
        ->and(executedCount())->toBe(1);
});

it('rejects the same key with a different body', function (): void {
    $key = (string) Str::uuid();
    $this->postJson('/api/v1/_test/idempotent', ['a' => 1], ['Idempotency-Key' => $key]);

    $this->postJson('/api/v1/_test/idempotent', ['a' => 2], ['Idempotency-Key' => $key])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused');

    expect(executedCount())->toBe(1);
});

it('does not store a key for a non-2xx response, so a retry may succeed', function (): void {
    $key = (string) Str::uuid();

    $this->postJson('/api/v1/_test/failing', [], ['Idempotency-Key' => $key])->assertStatus(409);

    expect(DB::table('idempotency_keys')->where('key', $key)->exists())->toBeFalse();
});
