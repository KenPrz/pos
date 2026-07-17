<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\IdempotencyKeyReused;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay protection for mutations. The subtlety: the key and the work it guards must
 * commit together or not at all, so THIS middleware opens the transaction and the
 * action's own DB::transaction() nests inside it as a savepoint.
 * See docs/04-backend-conventions.md ("Two subtleties").
 */
final class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return $next($request);
        }

        /*
         * The replay path below short-circuits BEFORE the controller, so FormRequest
         * authorization and the action's location scoping never run for a cache hit.
         * Folding the acting register and user into the hash confines a key to whoever
         * minted it: anyone else replaying the same key + body gets a 409, never a
         * cached money response from another till or another person's permissions.
         */
        $register = $request->attributes->get(EnsureDeviceToken::REGISTER);
        $hash = hash('sha256', implode('|', [
            $register?->id ?? '',
            $request->user()?->getAuthIdentifier() ?? '',
            $request->method(),
            $request->path(),
            $request->getContent(),
        ]));

        return DB::transaction(function () use ($key, $hash, $request, $next): Response {
            $seen = IdempotencyKey::whereKey($key)->lockForUpdate()->first();

            if ($seen !== null) {
                if (! hash_equals($seen->request_hash, $hash)) {
                    throw new IdempotencyKeyReused($key);
                }

                return response()->json($seen->response_body, $seen->response_code);
            }

            $response = $next($request);   // the action's DB::transaction() nests here

            // Only success earns a key. A 409 insufficient_stock must stay retryable —
            // the stock might arrive. Failed work rolled back to its savepoint above.
            if ($response->isSuccessful()) {
                IdempotencyKey::create([
                    'key' => $key,
                    'request_hash' => $hash,
                    'response_code' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                    'created_at' => now(),
                ]);
            }

            return $response;
        });
    }
}
