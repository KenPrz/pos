<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Laravel's AuthManager memoizes guard instances by name, and Sanctum's guard (a
     * RequestGuard) memoizes the resolved user on top of that — fine in production,
     * where one process handles one request, but our test harness reuses the same
     * booted application across every `postJson()` call in a test. Left alone, a second
     * call with a since-revoked bearer token would still authenticate as whoever the
     * first call resolved, because the cached RequestGuard never re-runs its callback.
     * Laravel Octane hits the identical problem for the identical reason and fixes it
     * the identical way: forget the guard before each request, so every simulated
     * request re-resolves the bearer token from scratch like a real one would.
     *
     * Only 'sanctum' is forgotten, not every guard: `actingAs()` pre-sets the *default*
     * guard (web, session-based) before the request is dispatched, and a blanket
     * `Auth::forgetGuards()` here would wipe that preset out from under it.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->forgetSanctumGuard();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function forgetSanctumGuard(): void
    {
        $auth = $this->app['auth'];
        $property = new \ReflectionProperty($auth, 'guards');
        $property->setAccessible(true);

        $guards = $property->getValue($auth);
        unset($guards['sanctum']);
        $property->setValue($auth, $guards);
    }
}
