<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->assertRequiredConfigPresent();

        // Fail loudly on a lazy load rather than firing N queries in a receipt render.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Assigning an unfillable attribute should be an error, not a silent no-op.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
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
