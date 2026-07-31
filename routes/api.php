<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashShiftController;
use App\Http\Controllers\CommerceMLImportController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerImportController;
use App\Http\Controllers\CustomerMergeController;
use App\Http\Controllers\DictionaryController;
use App\Http\Controllers\IssuanceController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TvBoardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\WarehouseController;
use App\Http\Middleware\EnforceLocationAccess;
use App\Http\Middleware\EnsureTenant;
use App\Http\Middleware\RateLimitAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware(RateLimitAuth::class);
});

Route::middleware([RateLimitAuth::class, 'auth', EnsureTenant::class, EnforceLocationAccess::class])->prefix('v1')->group(function (): void {
    Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('ensure.permission:orders.view');
    Route::get('orders', [OrderController::class, 'index'])->middleware('ensure.permission:orders.view');
    Route::post('orders', [OrderController::class, 'store'])->middleware('ensure.permission:orders.create');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('ensure.permission:orders.cancel');
    Route::delete('order-items/{item}', [OrderController::class, 'destroyItem'])->middleware('ensure.permission:orders.update');

    Route::post('issuances', [IssuanceController::class, 'store'])->middleware('ensure.permission:orders.update');
    Route::post('stock/transfers', [StockTransferController::class, 'store'])->middleware('ensure.permission:stock.transfer');

    Route::post('payments/{payment}/correct', [PaymentController::class, 'correct'])->middleware('ensure.permission:payments.correct');
    Route::post('payments', [PaymentController::class, 'store'])->middleware('ensure.permission:payments.create');

    Route::post('shifts/{shift}/close', [CashShiftController::class, 'close'])->middleware('ensure.permission:shifts.close');
    Route::post('shifts', [CashShiftController::class, 'store'])->middleware('ensure.permission:shifts.create');

    Route::get('settings', [SettingController::class, 'index'])->middleware('ensure.permission:settings.view');
    Route::put('settings', [SettingController::class, 'update'])->middleware('ensure.permission:settings.update');

    Route::get('dictionaries', [DictionaryController::class, 'index'])->middleware('ensure.permission:settings.view');
    Route::post('dictionaries', [DictionaryController::class, 'store'])->middleware('ensure.permission:settings.update');
    Route::post('dictionaries/{dictionary}/deactivate', [DictionaryController::class, 'deactivate'])->middleware('ensure.permission:settings.update');

    Route::get('users/{user}', [UserController::class, 'show'])->middleware('ensure.permission:users.view');
    Route::get('users', [UserController::class, 'index'])->middleware('ensure.permission:users.view');

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
