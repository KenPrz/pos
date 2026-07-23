<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminLogoutController;
use App\Http\Controllers\Admin\Audit\ListAuditLogController;
use App\Http\Controllers\Admin\Catalog\CreateCategoryController;
use App\Http\Controllers\Admin\Catalog\CreateDiscountController;
use App\Http\Controllers\Admin\Catalog\CreateModifierController;
use App\Http\Controllers\Admin\Catalog\CreateModifierGroupController;
use App\Http\Controllers\Admin\Catalog\CreateProductController;
use App\Http\Controllers\Admin\Catalog\CreateTaxRateController;
use App\Http\Controllers\Admin\Catalog\CreateVariantController;
use App\Http\Controllers\Admin\Catalog\ListCategoriesController;
use App\Http\Controllers\Admin\Catalog\ListDiscountsController;
use App\Http\Controllers\Admin\Catalog\ListModifierGroupsController;
use App\Http\Controllers\Admin\Catalog\ListModifiersController;
use App\Http\Controllers\Admin\Catalog\ListProductsController;
use App\Http\Controllers\Admin\Catalog\ListTaxRatesController;
use App\Http\Controllers\Admin\Catalog\ListVariantsController;
use App\Http\Controllers\Admin\Catalog\SetProductModifierGroupsController;
use App\Http\Controllers\Admin\Catalog\UpdateCategoryController;
use App\Http\Controllers\Admin\Catalog\UpdateDiscountController;
use App\Http\Controllers\Admin\Catalog\UpdateModifierController;
use App\Http\Controllers\Admin\Catalog\UpdateModifierGroupController;
use App\Http\Controllers\Admin\Catalog\UpdateProductController;
use App\Http\Controllers\Admin\Catalog\UpdateTaxRateController;
use App\Http\Controllers\Admin\Catalog\UpdateVariantController;
use App\Http\Controllers\Admin\Day\CloseBusinessDayController;
use App\Http\Controllers\Admin\Day\GetBusinessDayController;
use App\Http\Controllers\Admin\Day\ListBusinessDaysController;
use App\Http\Controllers\Admin\Day\ReopenBusinessDayController;
use App\Http\Controllers\Admin\Locations\CreateLocationController;
use App\Http\Controllers\Admin\Locations\ListLocationsController;
use App\Http\Controllers\Admin\Locations\UpdateLocationController;
use App\Http\Controllers\Admin\Registers\CreateRegisterController;
use App\Http\Controllers\Admin\Registers\IssueActivationCodeController;
use App\Http\Controllers\Admin\Registers\ListRegistersController;
use App\Http\Controllers\Admin\Registers\UpdateRegisterController;
use App\Http\Controllers\Admin\Reports\SalesReportController;
use App\Http\Controllers\Admin\Reports\StockReportController;
use App\Http\Controllers\Admin\Roles\CreateRoleController;
use App\Http\Controllers\Admin\Roles\DeleteRoleController;
use App\Http\Controllers\Admin\Roles\ListPermissionsController;
use App\Http\Controllers\Admin\Roles\ListRolesController;
use App\Http\Controllers\Admin\Roles\UpdateRoleController;
use App\Http\Controllers\Admin\Settings\GetSettingsController;
use App\Http\Controllers\Admin\Settings\UpdateSettingsController;
use App\Http\Controllers\Admin\Users\CreateUserController;
use App\Http\Controllers\Admin\Users\ListUsersController;
use App\Http\Controllers\Admin\Users\UpdateUserController;
use App\Http\Controllers\Auth\ActivateRegisterController;
use App\Http\Controllers\Auth\StaffLoginController;
use App\Http\Controllers\Auth\StaffLogoutController;
use App\Http\Controllers\Catalog\GetCatalogController;
use App\Http\Controllers\Catalog\LookupBarcodeController;
use App\Http\Controllers\Drawer\OpenDrawerNoSaleController;
use App\Http\Controllers\Orders\AddLineController;
use App\Http\Controllers\Orders\ApplyDiscountController;
use App\Http\Controllers\Orders\GetOrderController;
use App\Http\Controllers\Orders\ListOrdersController;
use App\Http\Controllers\Orders\OpenOrderController;
use App\Http\Controllers\Orders\ReceiptController;
use App\Http\Controllers\Orders\RemoveDiscountController;
use App\Http\Controllers\Orders\ReopenOrderController;
use App\Http\Controllers\Orders\SetLinePrepStateController;
use App\Http\Controllers\Orders\SetTableRefController;
use App\Http\Controllers\Orders\SettleZeroOrderController;
use App\Http\Controllers\Orders\SplitOrderController;
use App\Http\Controllers\Orders\TransferOrderController;
use App\Http\Controllers\Orders\UpdateLineQtyController;
use App\Http\Controllers\Orders\VoidLineController;
use App\Http\Controllers\Orders\VoidOrderController;
use App\Http\Controllers\Payments\TakePaymentController;
use App\Http\Controllers\Payments\VoidPaymentController;
use App\Http\Controllers\Refunds\RefundOrderController;
use App\Http\Controllers\Reports\GetZReportController;
use App\Http\Controllers\Shifts\ApproveVarianceController;
use App\Http\Controllers\Shifts\CloseShiftController;
use App\Http\Controllers\Shifts\CurrentShiftController;
use App\Http\Controllers\Shifts\OpenShiftController;
use App\Http\Controllers\Shifts\OpenShiftRegistersController;
use App\Http\Controllers\Shifts\RecordCashMovementController;
use App\Http\Controllers\Stock\AdjustStockController;
use App\Http\Controllers\Stock\CountStockController;
use App\Http\Controllers\Stock\GetStockMovementsController;
use App\Http\Controllers\Stock\ReceiveStockController;
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

    // The terminal's bootstrap: trades a one-time activation code (issued in the back
    // office) for the long-lived device token. Unauthenticated by design — the code IS
    // the credential — and throttled hard by IP because the code space is human-typeable.
    Route::post('/registers/activate', ActivateRegisterController::class)
        ->middleware('throttle:activate')
        ->name('registers.activate');

    // Back office: email+password, no device or location context. Every later admin
    // task (M6 tasks 2-7) adds routes inside the group below.
    Route::post('/admin/login', AdminLoginController::class)
        ->middleware('throttle:admin-login')
        ->name('admin.login');

    Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function (): void {
        Route::post('/logout', AdminLogoutController::class)->name('admin.logout');

        // Catalog CRUD (M6 task 2). No DELETE routes anywhere — archive via PATCH
        // is_active. Every mutation audits admin.<entity>.create|update.
        Route::get('/categories', ListCategoriesController::class)->name('admin.categories.list');
        Route::post('/categories', CreateCategoryController::class)->name('admin.categories.create');
        Route::patch('/categories/{category}', UpdateCategoryController::class)->name('admin.categories.update');

        Route::get('/tax-rates', ListTaxRatesController::class)->name('admin.tax-rates.list');
        Route::post('/tax-rates', CreateTaxRateController::class)->name('admin.tax-rates.create');
        Route::patch('/tax-rates/{tax_rate}', UpdateTaxRateController::class)->name('admin.tax-rates.update');

        Route::get('/products', ListProductsController::class)->name('admin.products.list');
        Route::post('/products', CreateProductController::class)->name('admin.products.create');
        Route::patch('/products/{product}', UpdateProductController::class)->name('admin.products.update');

        Route::get('/variants', ListVariantsController::class)->name('admin.variants.list');
        Route::post('/variants', CreateVariantController::class)->name('admin.variants.create');
        Route::patch('/variants/{variant}', UpdateVariantController::class)->name('admin.variants.update');

        // Modifier groups, modifiers, discounts (M6 task 3), plus the product<->group
        // attach endpoint. PUT, not PATCH: it replaces the full pivot set.
        Route::get('/modifier-groups', ListModifierGroupsController::class)->name('admin.modifier-groups.list');
        Route::post('/modifier-groups', CreateModifierGroupController::class)->name('admin.modifier-groups.create');
        Route::patch('/modifier-groups/{modifier_group}', UpdateModifierGroupController::class)->name('admin.modifier-groups.update');

        Route::get('/modifiers', ListModifiersController::class)->name('admin.modifiers.list');
        Route::post('/modifiers', CreateModifierController::class)->name('admin.modifiers.create');
        Route::patch('/modifiers/{modifier}', UpdateModifierController::class)->name('admin.modifiers.update');

        Route::get('/discounts', ListDiscountsController::class)->name('admin.discounts.list');
        Route::post('/discounts', CreateDiscountController::class)->name('admin.discounts.create');
        Route::patch('/discounts/{discount}', UpdateDiscountController::class)->name('admin.discounts.update');

        Route::put('/products/{product}/modifier-groups', SetProductModifierGroupsController::class)
            ->name('admin.products.modifier-groups.set');

        // User management (M6 task 4). Roles are a full-set replace per location; the
        // self-lockout guard lives in UpdateUser, not here.
        Route::get('/users', ListUsersController::class)->name('admin.users.list');
        Route::post('/users', CreateUserController::class)->name('admin.users.create');
        Route::patch('/users/{user}', UpdateUserController::class)->name('admin.users.update');

        // Role templates (RBAC v2): the runtime definition of a role, editable at
        // runtime and materialized per-location by RoleProvisioner. Delete, not
        // archive — role_templates has no is_active column, and DeleteRole refuses
        // while any assignment still points at it. Every mutation audits
        // admin.role.create|update|delete.
        Route::get('/roles', ListRolesController::class)->name('admin.roles.index');
        Route::post('/roles', CreateRoleController::class)->name('admin.roles.create');
        Route::patch('/roles/{role_template}', UpdateRoleController::class)->name('admin.roles.update');
        Route::post('/roles/{role_template}/delete', DeleteRoleController::class)->name('admin.roles.delete');

        // The grouped permission catalog — static data, backs the role editor and the
        // user-management screen's role picker.
        Route::get('/permissions', ListPermissionsController::class)->name('admin.permissions.index');

        // Location and register settings (M6 task 5). No DELETE routes here either —
        // archive via PATCH is_active, same as catalog.
        Route::get('/locations', ListLocationsController::class)->name('admin.locations.list');
        Route::post('/locations', CreateLocationController::class)->name('admin.locations.create');

        // End of Day (business-day close). Location-scoped; day.close gates read+close,
        // reopen is is_admin only. See the End-Of-Day design + docs/03-api.md. Registered
        // before the locations PATCH below so the more specific `/day` segments read
        // clearly next to the rest of the locations block (GET/POST vs PATCH don't
        // collide by method regardless of order).
        Route::get('/locations/{location}/day', GetBusinessDayController::class)
            ->where('location', '[0-9a-f-]{36}')->name('admin.day.get');
        Route::post('/locations/{location}/day/close', CloseBusinessDayController::class)
            ->where('location', '[0-9a-f-]{36}')->name('admin.day.close');
        Route::post('/locations/{location}/day/reopen', ReopenBusinessDayController::class)
            ->where('location', '[0-9a-f-]{36}')->name('admin.day.reopen');
        Route::get('/locations/{location}/days', ListBusinessDaysController::class)
            ->where('location', '[0-9a-f-]{36}')->name('admin.day.list');

        Route::patch('/locations/{location}', UpdateLocationController::class)->name('admin.locations.update');

        Route::get('/registers', ListRegistersController::class)->name('admin.registers.list');
        Route::post('/registers', CreateRegisterController::class)->name('admin.registers.create');
        Route::patch('/registers/{register}', UpdateRegisterController::class)->name('admin.registers.update');

        // Issues (or reissues) the register's one-time activation code — the only way a
        // terminal gets a device token. Reissue is the lost/stolen-terminal path: the till
        // goes dark immediately (device token + staff sessions revoked) and stays dark until
        // the new code is typed at the terminal. See ActivateRegister.
        Route::post('/registers/{register}/activation-code', IssueActivationCodeController::class)
            ->name('admin.registers.activation_code');

        // Business identity settings (RBAC-v2 task 7): database-backed, config fallback
        // until an admin sets a value. See App\Domain\Settings\Settings.
        Route::get('/settings', GetSettingsController::class)->name('admin.settings.get');
        Route::patch('/settings', UpdateSettingsController::class)->name('admin.settings.update');

        // Reports (M6 task 6). Reads only — no audit. day/user are ledger-basis (from
        // payments + refunds); category is line-basis (non-voided lines of closed
        // orders, joined to the live catalog) — see SalesReportResource's `basis` field.
        Route::get('/reports/sales', SalesReportController::class)->name('admin.reports.sales');
        Route::get('/reports/stock', StockReportController::class)->name('admin.reports.stock');

        // Audit-log viewer (M6 task 7). Read-only — no audit-of-the-audit.
        Route::get('/audit', ListAuditLogController::class)->name('admin.audit.list');
    });

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
            Route::post('/shifts/{shift}/cash-movements', RecordCashMovementController::class)
                ->middleware('idempotent')
                ->name('shifts.cash-movements.record');
            Route::post('/shifts/{shift}/close', CloseShiftController::class)
                ->middleware('idempotent')
                ->name('shifts.close');
            Route::post('/shifts/{shift}/approve-variance', ApproveVarianceController::class)
                ->name('shifts.approve-variance');

            // No idempotency middleware: a repeat is a genuinely separate drawer opening
            // and must produce its own audit row, not silently replay the first one.
            Route::post('/drawer/no-sale', OpenDrawerNoSaleController::class)
                ->name('drawer.no-sale');

            // Staff tier, not device tier: this is the register app cross-referencing
            // sibling tills at the same location (e.g. "approve my variance from another
            // open register"), which is a staff action even though it touches no money.
            Route::get('/registers/open-shifts', OpenShiftRegistersController::class)
                ->name('registers.open-shifts');

            Route::post('/orders', OpenOrderController::class)->name('orders.open');
            Route::get('/orders', ListOrdersController::class)->name('orders.list');
            Route::patch('/orders/{order}', SetTableRefController::class)->name('orders.update');
            Route::get('/orders/{order}', GetOrderController::class)->name('orders.get');
            Route::post('/orders/{order}/lines', AddLineController::class)
                ->middleware('idempotent')
                ->name('orders.lines.add');
            Route::patch('/orders/{order}/lines/{line}/prep', SetLinePrepStateController::class)
                ->name('orders.lines.prep');
            Route::patch('/orders/{order}/lines/{line}', UpdateLineQtyController::class)
                ->name('orders.lines.update');
            Route::delete('/orders/{order}/lines/{line}', VoidLineController::class)
                ->name('orders.lines.void');
            Route::post('/orders/{order}/discounts', ApplyDiscountController::class)
                ->name('orders.discounts.apply');
            Route::delete('/orders/{order}/discounts/{discount}', RemoveDiscountController::class)
                ->name('orders.discounts.remove');
            Route::post('/orders/{order}/payments', TakePaymentController::class)
                ->middleware('idempotent')
                ->name('orders.payments.take');
            Route::post('/payments/{payment}/void', VoidPaymentController::class)->name('payments.void');
            Route::post('/refunds', RefundOrderController::class)
                ->middleware('idempotent')
                ->name('refunds.create');
            Route::get('/orders/{order}/receipt', ReceiptController::class)->name('orders.receipt');
            Route::post('/orders/{order}/void', VoidOrderController::class)->name('orders.void');
            Route::post('/orders/{order}/settle', SettleZeroOrderController::class)->name('orders.settle');
            Route::post('/orders/{order}/reopen', ReopenOrderController::class)->name('orders.reopen');
            Route::post('/orders/{order}/transfer', TransferOrderController::class)->name('orders.transfer');
            Route::post('/orders/{order}/split', SplitOrderController::class)
                ->middleware('idempotent')
                ->name('orders.split');

            Route::get('/reports/z', GetZReportController::class)->name('reports.z');

            Route::post('/stock/adjustments', AdjustStockController::class)->name('stock.adjustments.create');
            Route::post('/stock/receipts', ReceiveStockController::class)->name('stock.receipts.create');
            Route::post('/stock/counts', CountStockController::class)->name('stock.counts.create');
            Route::get('/stock/movements', GetStockMovementsController::class)->name('stock.movements.get');
        });
    });
});
