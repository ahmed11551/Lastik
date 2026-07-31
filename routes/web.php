<?php

use App\Http\Controllers\CashShiftController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');

    Route::post('/cash-shifts', [CashShiftController::class, 'store'])->name('cash-shifts.store');
    Route::post('/cash-shifts/{shift}/close', [CashShiftController::class, 'close'])->name('cash-shifts.close');
});

Route::prefix('api')->middleware('throttle:60,1')->group(function () {
    require base_path('routes/api.php');
});
