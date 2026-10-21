<?php

use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\DayCloseController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FloorController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\RecipeController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\PinLoginController;
use App\Http\Controllers\Kds\KdsController;
use App\Http\Controllers\Pos\OrderController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\QzTrayController;
use App\Http\Controllers\Print\PrintStationController;
use App\Http\Controllers\Qr\QrMenuController;
use App\Http\Controllers\Receipt\ReceiptController;
use App\Http\Controllers\Shift\ShiftController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public (no login): QR self-order and shareable receipts
|--------------------------------------------------------------------------
*/
Route::prefix('m/{token}')->middleware('throttle:qr')->group(function () {
    Route::get('/', [QrMenuController::class, 'show'])->name('qr.show');
    Route::get('status', [QrMenuController::class, 'statusJson'])->name('qr.status');
    Route::post('order', [QrMenuController::class, 'store'])->middleware('throttle:qr-submit')->name('qr.order');
});
Route::get('r/{publicId}', [ReceiptController::class, 'show'])->middleware('throttle:60,1')->name('receipt.show');

/*
|--------------------------------------------------------------------------
| Staff
|--------------------------------------------------------------------------
*/
Route::post('login/pin', [PinLoginController::class, 'store'])->middleware(['guest', 'throttle:6,1'])->name('login.pin');

Route::get('/', function () {
    $user = auth()->user();
    if (! $user) {
        return redirect()->route('login');
    }

    return redirect()->route(match (true) {
        $user->can('view-sales-reports') => 'dashboard',
        $user->can('receive-payment') || $user->can('create-order') => 'pos.index',
        $user->can('update-kitchen-status') => 'kds.index',
        default => 'profile.edit',
    });
})->name('home');

Route::middleware(['auth', 'staff'])->group(function () {
    Route::get('dashboard', [ReportController::class, 'dashboard'])->middleware('permission:view-sales-reports')->name('dashboard');

    // Cashier / waiter
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->middleware('permission:create-order|receive-payment')->name('index');
        Route::get('state', [PosController::class, 'stateJson'])->middleware('permission:create-order|receive-payment')->name('state');

        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/bill-request', [OrderController::class, 'requestBill'])->name('orders.bill-request');
        Route::post('orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
        Route::post('orders/{order}/close-zero', [OrderController::class, 'closeZero'])->name('orders.close-zero');
        Route::post('orders/{order}/void', [OrderController::class, 'void'])->name('orders.void');
        Route::post('orders/{order}/discounts', [OrderController::class, 'discount'])->name('orders.discount');
        Route::delete('discounts/{discount}', [OrderController::class, 'removeDiscount'])->name('discounts.destroy');
        Route::post('orders/{order}/split', [OrderController::class, 'split'])->name('orders.split');
        Route::post('orders/{order}/carve', [OrderController::class, 'carve'])->name('orders.carve');
        Route::post('orders/{order}/carve/preview', [OrderController::class, 'carvePreview'])->name('orders.carve.preview');
        Route::delete('orders/{order}/split', [OrderController::class, 'cancelSplit'])->name('orders.split.cancel');
        Route::post('orders/{order}/allocate', [OrderController::class, 'allocate'])->name('orders.allocate');
        Route::post('orders/{order}/move-table', [OrderController::class, 'moveTable'])->name('orders.move-table');
        Route::post('orders/{order}/merge', [OrderController::class, 'merge'])->name('orders.merge');
        Route::post('orders/{order}/move-items', [OrderController::class, 'moveItems'])->name('orders.move-items');
        Route::post('items/{item}/void', [OrderController::class, 'voidItem'])->name('items.void');
        Route::post('items/{item}/comp', [OrderController::class, 'comp'])->name('items.comp');
        Route::post('rounds/{round}/confirm', [OrderController::class, 'confirmRound'])->name('rounds.confirm');
        Route::post('rounds/{round}/reject', [OrderController::class, 'rejectRound'])->name('rounds.reject');
        Route::post('payments/{payment}/refund', [OrderController::class, 'refund'])->name('payments.refund');

        Route::get('bills/{bill}/receipt', [ReceiptController::class, 'data'])->name('bills.receipt');
        Route::post('bills/{bill}/reprint', [ReceiptController::class, 'reprint'])->name('bills.reprint');

        Route::post('tables/{table}/qr', [FloorController::class, 'toggleQr'])->name('tables.qr');
        Route::post('products/{product}/availability', [MenuController::class, 'availability'])->name('products.availability');

        Route::get('qz/certificate', [QzTrayController::class, 'certificate'])->name('qz.certificate');
        Route::post('qz/sign', [QzTrayController::class, 'sign'])->name('qz.sign');
    });

    // Shift
    Route::prefix('shift')->name('shift.')->middleware('permission:open-close-shift')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->name('index');
        Route::post('open', [ShiftController::class, 'open'])->name('open');
        Route::post('movements', [ShiftController::class, 'movement'])->name('movement');
        Route::post('close', [ShiftController::class, 'close'])->name('close');
    });

    // Print station: a device that serves the queued print jobs of its printers
    Route::prefix('print-station')->name('print-station.')->middleware('permission:use-print-station')->group(function () {
        Route::get('/', [PrintStationController::class, 'show'])->name('show');
        Route::get('jobs', [PrintStationController::class, 'jobs'])->name('jobs');
        Route::post('jobs/{job}/done', [PrintStationController::class, 'done'])->name('done');
        Route::post('jobs/{job}/retry', [PrintStationController::class, 'retry'])->name('retry');
    });
    Route::post('pos/bills/{bill}/print', [PrintStationController::class, 'reprintReceipt'])->name('pos.bills.print');
    Route::post('pos/orders/{order}/print-bill', [PrintStationController::class, 'printBill'])->name('pos.orders.print-bill');

    // Kitchen display
    Route::prefix('kds')->name('kds.')->middleware(['permission:update-kitchen-status', 'kitchen'])->group(function () {
        Route::get('/', [KdsController::class, 'index'])->name('index');
        Route::get('{station}', [KdsController::class, 'show'])->name('show');
        Route::get('{station}/tickets', [KdsController::class, 'ticketsJson'])->name('tickets');
        Route::post('items/{item}/advance', [KdsController::class, 'advance'])->name('advance');
    });

    // Back office
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('permission:manage-menu')->group(function () {
            Route::get('menu', [MenuController::class, 'index'])->name('menu');
            Route::post('categories', [MenuController::class, 'saveCategory'])->name('categories.store');
            Route::put('categories/{category}', [MenuController::class, 'saveCategory'])->name('categories.update');
            Route::post('categories/{category}/printers', [MenuController::class, 'saveCategoryPrinters'])->name('categories.printers');
            Route::delete('categories/{category}', [MenuController::class, 'deleteCategory'])->name('categories.destroy');
            Route::post('stations', [MenuController::class, 'saveStation'])->name('stations.store');
            Route::put('stations/{station}', [MenuController::class, 'saveStation'])->name('stations.update');
            Route::post('products', [MenuController::class, 'saveProduct'])->name('products.store');
            Route::post('products/{product}', [MenuController::class, 'saveProduct'])->name('products.update'); // multipart needs POST
            Route::delete('products/{product}', [MenuController::class, 'deleteProduct'])->name('products.destroy');
            Route::post('modifier-groups', [MenuController::class, 'saveModifierGroup'])->name('modifier-groups.store');
            Route::put('modifier-groups/{group}', [MenuController::class, 'saveModifierGroup'])->name('modifier-groups.update');
            Route::delete('modifier-groups/{group}', [MenuController::class, 'deleteModifierGroup'])->name('modifier-groups.destroy');
        });

        Route::middleware('permission:manage-inventory')->group(function () {
            Route::get('inventory', [InventoryController::class, 'index'])->name('inventory');
            Route::post('inventory/ingredients', [InventoryController::class, 'storeIngredient'])->name('inventory.ingredients.store');
            Route::put('inventory/ingredients/{ingredient}', [InventoryController::class, 'updateIngredient'])->name('inventory.ingredients.update');
            Route::post('inventory/ingredients/{ingredient}/count', [InventoryController::class, 'count'])->name('inventory.count');
            Route::post('inventory/ingredients/{ingredient}/waste', [InventoryController::class, 'waste'])->name('inventory.waste');
            Route::post('inventory/purchases', [InventoryController::class, 'purchase'])->name('inventory.purchase');
            Route::get('recipes', [RecipeController::class, 'index'])->name('recipes');
            Route::post('recipes/products/{product}', [RecipeController::class, 'saveProduct'])->name('recipes.product');
            Route::post('recipes/modifiers/{modifier}', [RecipeController::class, 'saveModifier'])->name('recipes.modifier');
        });

        Route::middleware('permission:manage-tables')->group(function () {
            Route::get('tables', [FloorController::class, 'index'])->name('tables');
            Route::post('areas', [FloorController::class, 'saveArea'])->name('areas.store');
            Route::put('areas/{area}', [FloorController::class, 'saveArea'])->name('areas.update');
            Route::post('tables', [FloorController::class, 'saveTable'])->name('tables.store');
            Route::put('tables/{table}', [FloorController::class, 'saveTable'])->name('tables.update');
            Route::delete('tables/{table}', [FloorController::class, 'deleteTable'])->name('tables.destroy');
            Route::post('tables/{table}/regenerate-token', [FloorController::class, 'regenerateToken'])->name('tables.regenerate');
            Route::get('tables-qr.pdf', [FloorController::class, 'qrPdf'])->name('tables.qr-pdf');
        });

        Route::middleware('permission:manage-users')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
            Route::post('users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
            Route::post('users/{user}/pin', [UserController::class, 'setPin'])->name('users.pin');
        });

        Route::middleware('permission:manage-roles')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::middleware('permission:manage-printers')->group(function () {
            Route::get('printers', [PrinterController::class, 'index'])->name('printers');
            Route::post('printers', [PrinterController::class, 'store'])->name('printers.store');
            Route::put('printers/{printer}', [PrinterController::class, 'update'])->name('printers.update');
            Route::delete('printers/{printer}', [PrinterController::class, 'destroy'])->name('printers.destroy');
        });

        Route::middleware('permission:manage-settings')->group(function () {
            Route::get('settings', [SettingsController::class, 'show'])->name('settings');
            Route::post('settings/outlet', [SettingsController::class, 'updateOutlet'])->name('settings.outlet');
            Route::post('settings', [SettingsController::class, 'updateSettings'])->name('settings.update');
            Route::post('payment-methods', [SettingsController::class, 'savePaymentMethod'])->name('payment-methods.store');
            Route::put('payment-methods/{method}', [SettingsController::class, 'savePaymentMethod'])->name('payment-methods.update');
            Route::post('tax-rules', [SettingsController::class, 'saveTaxRule'])->name('tax-rules.store');
            Route::put('tax-rules/{rule}', [SettingsController::class, 'saveTaxRule'])->name('tax-rules.update');
            Route::post('promotions', [SettingsController::class, 'savePromotion'])->name('promotions.store');
            Route::put('promotions/{promotion}', [SettingsController::class, 'savePromotion'])->name('promotions.update');
            Route::delete('promotions/{promotion}', [SettingsController::class, 'deletePromotion'])->name('promotions.destroy');
            Route::post('expense-categories', [ExpenseController::class, 'saveCategory'])->name('expense-categories.store');
            Route::put('expense-categories/{category}', [ExpenseController::class, 'saveCategory'])->name('expense-categories.update');
        });

        Route::middleware('permission:input-expense')->group(function () {
            Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses');
            Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
            Route::get('expenses/export', [ExpenseController::class, 'export'])->name('expenses.export');
        });

        Route::middleware('permission:view-margin-report')->group(function () {
            Route::get('reports/margin', [ReportController::class, 'margin'])->name('reports.margin');
            Route::get('reports/margin/export', [ReportController::class, 'marginExport'])->name('reports.margin.export');
        });

        Route::middleware('permission:view-kitchen-report')->group(function () {
            Route::get('reports/kitchen', [ReportController::class, 'kitchen'])->name('reports.kitchen');
            Route::get('reports/kitchen/export', [ReportController::class, 'kitchenExport'])->name('reports.kitchen.export');
        });

        Route::middleware('permission:view-sales-reports')->group(function () {
            Route::get('reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
            Route::get('reports/adjustments', [ReportController::class, 'adjustments'])->name('reports.adjustments');
            Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
        });

        Route::middleware('permission:manage-shifts-any')->group(function () {
            Route::get('day-close', [DayCloseController::class, 'index'])->name('day-close');
            Route::post('day-close', [DayCloseController::class, 'close'])->name('day-close.store');
            Route::get('day-close/export', [DayCloseController::class, 'export'])->name('day-close.export');
        });

        Route::middleware('permission:view-accounting')->group(function () {
            Route::get('accounting', [AccountingController::class, 'index'])->name('accounting');
            Route::get('accounting/export', [AccountingController::class, 'export'])->name('accounting.export');
        });
        Route::middleware('permission:manage-accounting')->group(function () {
            Route::post('accounting/journals', [AccountingController::class, 'postJournal'])->name('accounting.journals.store');
            Route::post('accounting/journals/{entry}/reverse', [AccountingController::class, 'reverse'])->name('accounting.journals.reverse');
            Route::post('accounting/close-period', [AccountingController::class, 'closePeriod'])->name('accounting.close-period');
            Route::post('accounting/accounts', [AccountingController::class, 'saveAccount'])->name('accounting.accounts.store');
            Route::put('accounting/accounts/{account}', [AccountingController::class, 'saveAccount'])->name('accounting.accounts.update');
        });
    });
});

require __DIR__.'/settings.php';
