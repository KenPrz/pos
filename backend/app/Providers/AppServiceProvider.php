<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Auth\Pins;
use App\Domain\Payments\CashDriver;
use App\Domain\Payments\DriverRegistry;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Config is read at the edge and injected, so the value object stays pure and its
        // tests need no container. See docs/04-backend-conventions.md.
        $this->app->singleton(Pins::class, fn (): Pins => new Pins((string) config('app.key')));

        // Adding a processor = a driver class + one entry here. No action changes.
        $this->app->singleton(DriverRegistry::class,
            fn (): DriverRegistry => new DriverRegistry(
                new CashDriver,
            ));
    }

    public function boot(): void
    {
        $this->assertRequiredConfigPresent();

        // Fail loudly on a lazy load rather than firing N queries in a receipt render.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Assigning an unfillable attribute should be an error, not a silent no-op.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->grantAdminsEverything();
        $this->defineRateLimits();
    }

    /**
     * Deliberately loose: a busy lunch rush is not an attack, and a POS that rate-limits
     * a queue of real customers has failed at being a POS. The PIN limiter is the
     * exception — it is a real security control (see StaffLogin).
     */
    private function defineRateLimits(): void
    {
        RateLimiter::for('pin', fn (Request $request): Limit => Limit::perMinute(
            (int) config('pos.rate_limits.pin_per_minute')
        )->by($request->bearerToken() ?? $request->ip()));

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(
            (int) config('pos.rate_limits.default_per_minute')
        )->by($request->bearerToken() ?? $request->ip()));

        RateLimiter::for('catalog', fn (Request $request): Limit => Limit::perMinute(
            (int) config('pos.rate_limits.catalog_per_minute')
        )->by($request->bearerToken() ?? $request->ip()));
    }

    /**
     * Admin is the one capability that is genuinely global, and spatie's teams cannot
     * express a role assignment that spans locations — every assignment pins to exactly
     * one. So admin is a flag and bypasses the gate. This is spatie's own documented
     * super-admin pattern. See docs/05-rbac.md.
     *
     * Returning null (not false) is essential: false would deny everyone else outright
     * instead of letting the normal permission checks run.
     */
    private function grantAdminsEverything(): void
    {
        Gate::before(fn (User $user): ?bool => $user->is_admin ? true : null);
    }

    /**
     * A null currency does not fail loudly on its own — it produces a wrong receipt at
     * lunchtime. Stop the app instead. See docs/04-backend-conventions.md.
     */
    private function assertRequiredConfigPresent(): void
    {
        /** @var list<string> $required */
        $required = config('pos.required', []);

        $missing = array_values(array_filter(
            $required,
            static fn (string $key): bool => blank(config($key)),
        ));

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing required POS configuration: '.implode(', ', $missing).
                '. Check your .env against .env.example.'
            );
        }
    }
}
