<?php

use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\CashLedgerController;
use App\Http\Controllers\Admin\DayCloseController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\DiscountPresetController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FloorController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\OnlinePaymentController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\ReceiptExportController;
use App\Http\Controllers\Admin\RecipeController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Asset\AssetController;
use App\Http\Controllers\Auth\PinLoginController;
use App\Http\Controllers\Import\ImportController;
use App\Http\Controllers\Kds\KdsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Pos\OrderController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\QzTrayController;
use App\Http\Controllers\Pos\ServiceCallController;
use App\Http\Controllers\Pos\SoldOutController;
use App\Http\Controllers\Pos\TodayController;
use App\Http\Controllers\Print\PrintStationController;
use App\Http\Controllers\Qr\QrMenuController;
use App\Http\Controllers\Qr\QrPaymentController;
use App\Http\Controllers\QrisWebhookController;
use App\Http\Controllers\Receipt\ReceiptController;
use App\Http\Controllers\Shift\ShiftController;
use App\Http\Controllers\Stock\StockController;
use App\Http\Controllers\Stock\WasteController;
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
    Route::post('call', [QrMenuController::class, 'call'])->middleware('throttle:qr-call')->name('qr.call');
    Route::post('pay', [QrPaymentController::class, 'start'])->middleware('throttle:qr-submit')->name('qr.pay');
    Route::get('pay/{publicId}', [QrPaymentController::class, 'status'])->name('qr.pay.status');
    Route::post('pay/{publicId}/simulate', [QrPaymentController::class, 'simulate'])->middleware('throttle:qr-submit')->name('qr.pay.simulate');
});
Route::post('webhooks/qris/{provider}', QrisWebhookController::class)->middleware('throttle:120,1')->name('webhooks.qris');
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
    Route::get('today', TodayController::class)->middleware('permission:view-today-summary')->name('today');
    Route::get('dashboard', [ReportController::class, 'dashboard'])->middleware('permission:view-sales-reports')->name('dashboard');

    // Cashier / waiter
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->middleware('permission:create-order|receive-payment')->name('index');
        Route::get('state', [PosController::class, 'stateJson'])->middleware('permission:create-order|receive-payment')->name('state');
        Route::get('history', [PosController::class, 'history'])->middleware('permission:create-order|receive-payment')->name('history');

        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/bill-request', [OrderController::class, 'requestBill'])->name('orders.bill-request');
        Route::post('orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
        Route::post('orders/{order}/close-zero', [OrderController::class, 'closeZero'])->name('orders.close-zero');
        Route::post('orders/{order}/void', [OrderController::class, 'void'])->name('orders.void');
        Route::post('orders/{order}/discounts', [OrderController::class, 'discount'])->name('orders.discount');
        Route::post('orders/{order}/discount-presets/{preset}', [OrderController::class, 'discountPreset'])->name('orders.discount-preset');
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
        Route::get('calls', [ServiceCallController::class, 'index'])->middleware('permission:handle-calls')->name('calls');
        Route::post('calls/{call}/done', [ServiceCallController::class, 'done'])->middleware('permission:handle-calls')->name('calls.done');
        Route::post('products/{product}/stock', [SoldOutController::class, 'stock'])->name('products.stock');
        Route::post('modifiers/{modifier}/availability', [SoldOutController::class, 'modifier'])->name('modifiers.availability');
        Route::post('variants/{variant}/availability', [SoldOutController::class, 'variant'])->name('variants.availability');
        Route::post('products/{product}/availability', [MenuController::class, 'availability'])->name('products.availability');

        Route::get('qz/certificate', [QzTrayController::class, 'certificate'])->name('qz.certificate');
        Route::post('qz/sign', [QzTrayController::class, 'sign'])->name('qz.sign');
    });

    // Shift
    // One shift per store. Every staff role may look at it (and its history); only the shift permission acts on it.
    Route::prefix('shift')->name('shift.')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->middleware('permission:open-close-shift|manage-shifts-any|view-own-shift-recap|create-order|receive-payment')->name('index');
        Route::post('{shift}/reopen', [ShiftController::class, 'reopen'])->middleware('permission:reopen-shift')->name('reopen');
        Route::middleware('permission:open-close-shift')->group(function () {
            Route::post('open', [ShiftController::class, 'open'])->name('open');
            Route::post('movements', [ShiftController::class, 'movement'])->name('movement');
            Route::post('close', [ShiftController::class, 'close'])->name('close');
        });
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

    // Stock list and counting for staff
    Route::get('stock', [StockController::class, 'index'])->middleware('permission:view-stock')->name('stock.index');
    Route::middleware('permission:count-stock')->prefix('stock/count')->name('stock.')->group(function () {
        Route::get('/', [StockController::class, 'count'])->name('count');
        Route::post('save', [StockController::class, 'save'])->name('count.save');
        Route::post('submit', [StockController::class, 'submit'])->name('count.submit');
    });

    // In-app notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id')->name('notifications.read');

    // Excel imports and templates
    Route::prefix('import/{type}')->name('import.')->group(function () {
        Route::get('/', [ImportController::class, 'info'])->name('info');
        Route::get('template', [ImportController::class, 'template'])->name('template');
        Route::post('preview', [ImportController::class, 'preview'])->name('preview');
        Route::post('apply', [ImportController::class, 'apply'])->name('apply');
    });

    // Waste (staff)
    Route::middleware('permission:record-waste')->prefix('stock/waste')->name('stock.waste.')->group(function () {
        Route::get('/', [WasteController::class, 'index'])->name('index');
        Route::post('ingredient', [WasteController::class, 'ingredient'])->name('ingredient');
        Route::post('product', [WasteController::class, 'product'])->name('product');
        Route::post('reasons', [WasteController::class, 'saveReason'])->name('reasons.store');
        Route::put('reasons/{reason}', [WasteController::class, 'saveReason'])->name('reasons.update');
    });

    // Shopping for ingredients (staff)
    Route::middleware('permission:record-purchase')->prefix('stock/purchase')->name('stock.')->group(function () {
        Route::get('/', [StockController::class, 'purchase'])->name('purchase');
        Route::post('/', [StockController::class, 'submitPurchase'])->name('purchase.submit');
    });

    // Equipment register
    Route::get('assets', [AssetController::class, 'index'])->middleware('permission:view-assets')->name('assets.index');
    Route::get('assets/export', [AssetController::class, 'export'])->middleware('permission:view-assets')->name('assets.export');
    Route::middleware('permission:manage-assets')->prefix('assets')->name('assets.')->group(function () {
        Route::post('/', [AssetController::class, 'store'])->name('store');
        Route::put('{asset}', [AssetController::class, 'update'])->name('update');
        Route::delete('{asset}', [AssetController::class, 'destroy'])->name('destroy');
        Route::post('{asset}/logs', [AssetController::class, 'log'])->name('log');
    });

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
            Route::post('products/{product}/active', [MenuController::class, 'setActive'])->name('products.active');
            Route::delete('products/{product}', [MenuController::class, 'deleteProduct'])->name('products.destroy');
            Route::post('modifier-groups', [MenuController::class, 'saveModifierGroup'])->name('modifier-groups.store');
            Route::put('modifier-groups/{group}', [MenuController::class, 'saveModifierGroup'])->name('modifier-groups.update');
            Route::delete('modifier-groups/{group}', [MenuController::class, 'deleteModifierGroup'])->name('modifier-groups.destroy');
        });

        Route::middleware('permission:export-receipts')->group(function () {
            Route::get('receipt-exports', [ReceiptExportController::class, 'index'])->name('receipt-exports');
            Route::get('receipt-exports/preview', [ReceiptExportController::class, 'preview'])->name('receipt-exports.preview');
            Route::post('receipt-exports', [ReceiptExportController::class, 'store'])->name('receipt-exports.store');
            Route::get('receipt-exports/{export}/download', [ReceiptExportController::class, 'download'])->name('receipt-exports.download');
            Route::delete('receipt-exports/{export}', [ReceiptExportController::class, 'destroy'])->name('receipt-exports.destroy');
        });
        Route::middleware('permission:manage-online-payments')->group(function () {
            Route::get('online-payments', [OnlinePaymentController::class, 'index'])->name('online-payments');
            Route::post('online-payments/{onlinePayment}/retry', [OnlinePaymentController::class, 'retry'])->name('online-payments.retry');
            Route::post('online-payments/{onlinePayment}/simulate', [OnlinePaymentController::class, 'simulate'])->name('online-payments.simulate');
        });
        Route::middleware('permission:manage-suppliers')->group(function () {
            Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers');
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        });

        Route::middleware('permission:view-waste-report')->group(function () {
            Route::get('reports/waste', [ReportController::class, 'waste'])->name('reports.waste');
            Route::get('reports/waste/export', [ReportController::class, 'wasteExport'])->name('reports.waste.export');
        });

        Route::middleware('permission:manage-inventory')->group(function () {
            Route::get('inventory', [InventoryController::class, 'index'])->name('inventory');
            Route::post('inventory/ingredients', [InventoryController::class, 'storeIngredient'])->name('inventory.ingredients.store');
            Route::put('inventory/ingredients/{ingredient}', [InventoryController::class, 'updateIngredient'])->name('inventory.ingredients.update');
            Route::post('inventory/ingredients/{ingredient}/count', [InventoryController::class, 'count'])->name('inventory.count');
            Route::post('inventory/ingredients/{ingredient}/waste', [InventoryController::class, 'waste'])->name('inventory.waste');
            Route::post('inventory/counts/{count}/approve', [InventoryController::class, 'approveCount'])->name('inventory.counts.approve');
            Route::post('inventory/counts/{count}/reject', [InventoryController::class, 'rejectCount'])->name('inventory.counts.reject');
            Route::post('inventory/purchases/{purchase}/approve', [InventoryController::class, 'approvePurchase'])->name('inventory.purchases.approve');
            Route::post('inventory/purchases/{purchase}/reject', [InventoryController::class, 'rejectPurchase'])->name('inventory.purchases.reject');
            Route::post('inventory/purchases', [InventoryController::class, 'purchase'])->name('inventory.purchase');
            Route::get('recipes', [RecipeController::class, 'index'])->name('recipes');
            Route::post('recipes/products/{product}', [RecipeController::class, 'saveProduct'])->name('recipes.product');
            Route::post('recipes/modifiers/{modifier}', [RecipeController::class, 'saveModifier'])->name('recipes.modifier');
        });

        Route::middleware('permission:manage-tables')->group(function () {
            Route::get('tables', [FloorController::class, 'index'])->name('tables');
            Route::post('areas', [FloorController::class, 'saveArea'])->name('areas.store');
            Route::put('areas/{area}', [FloorController::class, 'saveArea'])->name('areas.update');
            Route::delete('areas/{area}', [FloorController::class, 'deleteArea'])->name('areas.destroy');
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

        Route::middleware('permission:manage-discount-presets')->group(function () {
            Route::get('discounts', [DiscountController::class, 'index'])->name('discounts');
            Route::post('discount-presets', [DiscountPresetController::class, 'save'])->name('discount-presets.store');
            Route::put('discount-presets/{preset}', [DiscountPresetController::class, 'save'])->name('discount-presets.update');
            Route::post('promotions', [SettingsController::class, 'savePromotion'])->name('promotions.store');
            Route::put('promotions/{promotion}', [SettingsController::class, 'savePromotion'])->name('promotions.update');
            Route::delete('promotions/{promotion}', [SettingsController::class, 'deletePromotion'])->name('promotions.destroy');
        });
        Route::middleware('permission:manage-pricing')->group(function () {
            Route::get('payment-settings', [PaymentSettingsController::class, 'index'])->name('payment-settings');
            Route::post('payment-methods', [SettingsController::class, 'savePaymentMethod'])->name('payment-methods.store');
            Route::put('payment-methods/{method}', [SettingsController::class, 'savePaymentMethod'])->name('payment-methods.update');
            Route::post('tax-rules', [SettingsController::class, 'saveTaxRule'])->name('tax-rules.store');
            Route::put('tax-rules/{rule}', [SettingsController::class, 'saveTaxRule'])->name('tax-rules.update');
        });
        Route::middleware('permission:manage-settings')->group(function () {
            Route::get('settings', [SettingsController::class, 'show'])->name('settings');
            Route::post('settings/outlet', [SettingsController::class, 'updateOutlet'])->name('settings.outlet');
            Route::post('settings', [SettingsController::class, 'updateSettings'])->name('settings.update');
            Route::post('settings/test-notification', [SettingsController::class, 'testNotification'])->name('settings.test-notification');
            Route::post('expense-categories', [ExpenseController::class, 'saveCategory'])->name('expense-categories.store');
            Route::put('expense-categories/{category}', [ExpenseController::class, 'saveCategory'])->name('expense-categories.update');
        });

        Route::middleware('permission:input-expense')->group(function () {
            Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses');
            Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
            Route::get('expenses/export', [ExpenseController::class, 'export'])->name('expenses.export');
        });

        Route::middleware('permission:view-cash-ledger')->group(function () {
            Route::get('cash-ledger', [CashLedgerController::class, 'index'])->name('cash-ledger');
            Route::get('cash-ledger/export', [CashLedgerController::class, 'export'])->name('cash-ledger.export');
        });

        Route::middleware('permission:view-activity-log')->group(function () {
            Route::get('activity', [ActivityController::class, 'index'])->name('activity');
            Route::get('activity/export', [ActivityController::class, 'export'])->name('activity.export');
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
            Route::post('day-close/send', [DayCloseController::class, 'send'])->name('day-close.send');
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
