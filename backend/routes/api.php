<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\EnrollRegisterController;
use App\Http\Controllers\Auth\StaffLoginController;
use App\Http\Controllers\Auth\StaffLogoutController;
use App\Http\Controllers\Catalog\GetCatalogController;
use App\Http\Controllers\Catalog\LookupBarcodeController;
use App\Http\Controllers\Orders\AddLineController;
use App\Http\Controllers\Orders\GetOrderController;
use App\Http\Controllers\Orders\ListOrdersController;
use App\Http\Controllers\Orders\OpenOrderController;
use App\Http\Controllers\Orders\ReceiptController;
use App\Http\Controllers\Payments\TakePaymentController;
use App\Http\Controllers\Shifts\CloseShiftController;
use App\Http\Controllers\Shifts\CurrentShiftController;
use App\Http\Controllers\Shifts\OpenShiftController;
use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\Route;

/*
| One system action = one route = one single-action controller.
| See docs/03-api.md for the surface and docs/04-backend-conventions.md for the shape.
|
| Three tiers of access, deliberately:
|   (none)   — health only
|   device   — the terminal is enrolled. Can read; cannot touch money.
|   staff    — someone entered a PIN. Sets the permission team context from the register.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    // Enrolment is bootstrapped by a back-office admin, so it authenticates with a user
    // session rather than a device token — the device has no identity yet.
    Route::post('/registers/enroll', EnrollRegisterController::class)
        ->middleware('auth:sanctum')
        ->name('registers.enroll');

    Route::middleware('device')->group(function (): void {
        Route::post('/staff/login', StaffLoginController::class)
            ->middleware('throttle:pin')
            ->name('staff.login');

        // Device tier — a terminal showing the menu before anyone clocks in is normal.
        Route::get('/catalog', GetCatalogController::class)
            ->middleware('throttle:catalog')
            ->name('catalog.get');
        Route::get('/catalog/lookup', LookupBarcodeController::class)->name('catalog.lookup');

        Route::middleware('staff')->group(function (): void {
            Route::post('/staff/logout', StaffLogoutController::class)->name('staff.logout');

            Route::post('/shifts/open', OpenShiftController::class)->name('shifts.open');
            Route::get('/shifts/current', CurrentShiftController::class)->name('shifts.current');
            Route::post('/shifts/{shift}/close', CloseShiftController::class)
                ->middleware('idempotent')
                ->name('shifts.close');

            Route::post('/orders', OpenOrderController::class)->name('orders.open');
            Route::get('/orders', ListOrdersController::class)->name('orders.list');
            Route::get('/orders/{order}', GetOrderController::class)->name('orders.get');
            Route::post('/orders/{order}/lines', AddLineController::class)
                ->middleware('idempotent')
                ->name('orders.lines.add');
            Route::post('/orders/{order}/payments', TakePaymentController::class)
                ->middleware('idempotent')
                ->name('orders.payments.take');
            Route::get('/orders/{order}/receipt', ReceiptController::class)->name('orders.receipt');
        });
    });
});
