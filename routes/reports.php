<?php

use App\Http\Controllers\CashierShiftController;
use App\Http\Controllers\Reports\ExpenseReportController;
use App\Http\Controllers\Reports\OutOfStockReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('reports')->name('reports.')->group(function (): void {
    Route::get('out-of-stock', OutOfStockReportController::class)->middleware('can:inventory.view')->name('out-of-stock');
    Route::get('expenses', ExpenseReportController::class)->middleware('can:shifts.view')->name('expenses');
    Route::get('current-shift', [CashierShiftController::class, 'current'])->middleware('can:shifts.view')->name('current-shift');
});
