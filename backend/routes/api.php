<?php

declare(strict_types=1);

use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\Route;

/*
| One system action = one route = one single-action controller.
| See docs/03-api.md for the surface, docs/04-backend-conventions.md for the shape.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');
});
