<?php

declare(strict_types=1);

use App\Actions\System\CheckHealth;
use App\Domain\System\HealthStatus;
use Illuminate\Database\ConnectionInterface;

/**
 * Actions are final, so they are never subclassed or mocked. Instead we build a real
 * action with a failing collaborator — which is only possible because the action takes
 * its dependencies through the constructor. See docs/04-backend-conventions.md.
 */
function healthActionWithBrokenDatabase(string $reason): CheckHealth
{
    $db = Mockery::mock(ConnectionInterface::class);
    $db->shouldReceive('selectOne')->andThrow(new RuntimeException($reason));

    return new CheckHealth($db);
}

it('reports healthy when the database answers', function (): void {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('data.healthy', true)
        ->assertJsonPath('data.database.ok', true);

    // Proves the suite runs on real Postgres, not SQLite. See docs/01-architecture.md.
    expect($response->json('data.database.version'))->toContain('PostgreSQL 18');
});

it('returns 503 when the database is unreachable', function (): void {
    $this->app->instance(CheckHealth::class, healthActionWithBrokenDatabase('connection refused'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('data.healthy', false)
        ->assertJsonPath('data.database.reason', 'connection refused');
});

it('reports an unreachable database as a status, not an exception', function (): void {
    // Monitoring needs a body explaining what is wrong, not a stack trace.
    $status = healthActionWithBrokenDatabase('no route to host')->execute();

    expect($status->isHealthy())->toBeFalse()
        ->and($status->failureReason)->toBe('no route to host');
});

it('runs the action with no HTTP layer at all', function (): void {
    // No kernel, no route, no serialization — the point of rule 1 in the conventions.
    $status = app(CheckHealth::class)->execute();

    expect($status)->toBeInstanceOf(HealthStatus::class)
        ->and($status->isHealthy())->toBeTrue()
        ->and($status->databaseVersion)->toContain('PostgreSQL 18');
});
