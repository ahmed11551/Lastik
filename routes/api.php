<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Http\Controllers\AuditLogController;
use Autometria\Http\Controllers\AuthController;
use Autometria\Http\Controllers\CashShiftController;
use Autometria\Http\Controllers\CommerceMLImportController;
use Autometria\Http\Controllers\CustomerController;
use Autometria\Http\Controllers\CustomerImportController;
use Autometria\Http\Controllers\CustomerMergeController;
use Autometria\Http\Controllers\DictionaryController;
use Autometria\Http\Controllers\IssuanceController;
use Autometria\Http\Controllers\KpiController;
use Autometria\Http\Controllers\ModuleController;
use Autometria\Http\Controllers\OrderController;
use Autometria\Http\Controllers\PaymentController;
use Autometria\Http\Controllers\PosController;
use Autometria\Http\Controllers\ProductController;
use Autometria\Http\Controllers\SearchController;
use Autometria\Http\Controllers\SettingController;
use Autometria\Http\Controllers\StockController;
use Autometria\Http\Controllers\StockTransferController;
use Autometria\Http\Controllers\TaskController;
use Autometria\Http\Controllers\TvBoardController;
use Autometria\Http\Controllers\UserController;
use Autometria\Http\Controllers\VehicleController;
use Autometria\Http\Controllers\WarehouseController;
use Autometria\Http\Middleware\EnforceLocationAccess;
use Autometria\Http\Middleware\EnsureTenant;
use Autometria\Http\Middleware\RateLimitAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware(RateLimitAuth::class);
});

Route::middleware([RateLimitAuth::class, 'auth:sanctum', EnsureTenant::class, EnforceLocationAccess::class])->prefix('v1')->group(function (): void {
    Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('ensure.permission:orders.view');
    Route::get('orders', [OrderController::class, 'index'])->middleware('ensure.permission:orders.view');
    Route::post('orders', [OrderController::class, 'store'])->middleware('ensure.permission:orders.create');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('ensure.permission:orders.cancel');
    Route::delete('order-items/{item}', [OrderController::class, 'destroyItem'])->middleware('ensure.permission:orders.update');

    Route::post('issuances', [IssuanceController::class, 'store'])->middleware('ensure.permission:orders.update');
    Route::post('stock/transfers', [StockTransferController::class, 'store'])->middleware('ensure.permission:stock.transfer');
    Route::get('stock', [StockController::class, 'index'])->middleware('ensure.permission:stock.view');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('ensure.permission:admin.dashboard');
    Route::get('kpi/summary', [KpiController::class, 'summary'])->middleware('ensure.permission:admin.dashboard');

    Route::post('payments/{payment}/correct', [PaymentController::class, 'correct'])->middleware('ensure.permission:payments.correct');
    Route::post('payments', [PaymentController::class, 'store'])->middleware('ensure.permission:payments.create');

    Route::get('shifts/current', [CashShiftController::class, 'current'])->middleware('ensure.permission:shifts.create');
    Route::post('shifts/open', [CashShiftController::class, 'openShift'])->middleware('ensure.permission:shifts.create');
    Route::post('shifts/close', [CashShiftController::class, 'closeCurrent'])->middleware('ensure.permission:shifts.close');
    Route::post('shifts/{shift}/close', [CashShiftController::class, 'close'])->middleware('ensure.permission:shifts.close');
    Route::post('shifts', [CashShiftController::class, 'store'])->middleware('ensure.permission:shifts.create');
    Route::get('shifts', [CashShiftController::class, 'index'])->middleware('ensure.permission:shifts.create');

    Route::post('pos/checkout', [PosController::class, 'checkout'])->middleware('ensure.permission:payments.create');

    Route::get('settings', [SettingController::class, 'index'])->middleware('ensure.permission:settings.view');
    Route::put('settings', [SettingController::class, 'update'])->middleware('ensure.permission:settings.update');

    Route::get('dictionaries', [DictionaryController::class, 'index'])->middleware('ensure.permission:settings.view');
    Route::post('dictionaries', [DictionaryController::class, 'store'])->middleware('ensure.permission:settings.update');
    Route::post('dictionaries/{dictionary}/deactivate', [DictionaryController::class, 'deactivate'])->middleware('ensure.permission:settings.update');

    Route::get('users/{user}', [UserController::class, 'show'])->middleware('ensure.permission:users.view');
    Route::get('users', [UserController::class, 'index'])->middleware('ensure.permission:users.view');
    Route::post('users', [UserController::class, 'store'])->middleware('ensure.permission:users.create');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('ensure.permission:users.update');

    Route::get('customers', [CustomerController::class, 'index'])->middleware('ensure.permission:customers.view');
    Route::post('customers/import', [CustomerImportController::class, 'store'])->middleware('ensure.permission:customers.create');
    Route::post('customers/merge', [CustomerMergeController::class, 'store'])->middleware('ensure.permission:customers.update');

    Route::post('imports/commerceml', [CommerceMLImportController::class, 'store'])->middleware('ensure.permission:stock.import');
    Route::get('stock/conflicts', [CommerceMLImportController::class, 'conflicts'])->middleware('ensure.permission:stock.view');
    Route::post('stock/conflicts/{conflict}/resolve', [CommerceMLImportController::class, 'resolveConflict'])->middleware('ensure.permission:stock.import');

    Route::get('vehicles', [VehicleController::class, 'index'])->middleware('ensure.permission:customers.view');
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('ensure.permission:stock.view');
    Route::get('products', [ProductController::class, 'index'])->middleware('ensure.permission:products.view');

    Route::get('modules', [ModuleController::class, 'index'])->middleware('ensure.permission:modules.view');
    Route::post('modules/{slug}/enable', [ModuleController::class, 'enable'])->middleware('ensure.permission:modules.update');
    Route::post('modules/{slug}/disable', [ModuleController::class, 'disable'])->middleware('ensure.permission:modules.update');

    Route::get('tasks', [TaskController::class, 'index']);
    Route::post('tasks', [TaskController::class, 'store']);
    Route::post('tasks/{task}/complete', [TaskController::class, 'complete']);
    Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel']);

    Route::get('search', SearchController::class)->middleware('ensure.permission:orders.view');
    Route::get('tv/board', TvBoardController::class)->middleware('ensure.permission:orders.view');
});
