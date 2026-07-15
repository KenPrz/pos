<?php

declare(strict_types=1);

use App\Exceptions\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The render hook in bootstrap/app.php is the single definition of the error envelope in
 * docs/03-api.md. M0 has no domain exception to throw yet, so we register a throwaway
 * route and a fixture exception to prove the wiring before M2 depends on it.
 */
final class FixtureFailure extends DomainException
{
    public function __construct()
    {
        parent::__construct('Only 2 units of SKU-1234 remain.');
    }

    public function errorCode(): string
    {
        return 'insufficient_stock';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['requested' => 5, 'available' => 2];
    }
}

it('renders a domain exception as the standard error envelope', function (): void {
    Route::middleware('api')->get('/api/v1/__fixture', fn () => throw new FixtureFailure);

    $this->getJson('/api/v1/__fixture')
        ->assertStatus(409)
        ->assertExactJson([
            'error' => [
                'code'    => 'insufficient_stock',
                'message' => 'Only 2 units of SKU-1234 remain.',
                'details' => ['requested' => 5, 'available' => 2],
            ],
        ]);
});

it('never returns data alongside error', function (): void {
    Route::middleware('api')->get('/api/v1/__fixture2', fn () => throw new FixtureFailure);

    $body = $this->getJson('/api/v1/__fixture2')->json();

    expect($body)->toHaveKey('error')
        ->and($body)->not->toHaveKey('data');
});

/*
| The framework's own exceptions are the half that leaks. A 404 rendering Laravel's
| default {"message": ...} would break the one-code-path promise in docs/03-api.md just
| as thoroughly as a malformed DomainException.
*/

it('renders an unknown route in the standard envelope', function (): void {
    $response = $this->getJson('/api/v1/definitely-not-a-route')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('trace');

    // details is an object even when empty, never a JSON array.
    expect(str_contains($response->getContent(), '"details":{}'))->toBeTrue();
});

it('renders a wrong method in the standard envelope', function (): void {
    $this->postJson('/api/v1/health')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
});

it('renders validation failures as 400 with the offending fields', function (): void {
    Route::middleware('api')->post('/api/v1/__validate', function (Request $request) {
        $request->validate(['qty' => ['required', 'string']]);
    });

    $this->postJson('/api/v1/__validate', [])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['qty']]]]);
});
