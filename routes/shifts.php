<?php

use App\Http\Controllers\CashierShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:shifts.view'])->prefix('cashier-shifts')->name('shifts.')->controller(CashierShiftController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/open', 'create')->middleware('can:shifts.open')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{shift}', 'show')->name('show');
    Route::get('/{shift}/movements', 'movements')->name('movements');
    Route::get('/{shift}/movements/{movement}', 'movement')->name('movement');
    Route::post('/{shift}/movements', 'storeMovement')->name('store-movement');
    Route::post('/{shift}/movements/{movement}/reverse', 'reverse')->name('reverse');
    Route::get('/{shift}/close', 'closing')->name('closing');
    Route::post('/{shift}/close', 'close')->name('close');
    Route::post('/{shift}/approve', 'approve')->name('approve');
    Route::get('/{shift}/report', 'report')->middleware('can:shifts.print')->name('report');
});
