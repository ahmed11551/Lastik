<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

use Autometria\Http\Controllers\CashShiftController;
use Autometria\Http\Controllers\DashboardController;
use Autometria\Http\Controllers\OrderController;
use Autometria\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/users', UserManagementController::class)->name('users.index');

    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');

    Route::post('/cash-shifts', [CashShiftController::class, 'store'])->name('cash-shifts.store');
    Route::post('/cash-shifts/{shift}/close', [CashShiftController::class, 'close'])->name('cash-shifts.close');
});

Route::prefix('api')->middleware('throttle:60,1')->group(function () {
    require base_path('routes/api.php');
});
