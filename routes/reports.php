<?php

use App\Http\Controllers\CashierShiftController;
use App\Http\Controllers\Reports\DamagedStockReportController;
use App\Http\Controllers\Reports\ExpenseReportController;
use App\Http\Controllers\Reports\OutOfStockReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('reports')->name('reports.')->group(function (): void {
    Route::get('damaged-stock', DamagedStockReportController::class)->middleware('can:damaged-reports.view')->name('damaged');
    Route::get('damaged-stock/print', DamagedStockReportController::class)->middleware(['can:damaged-reports.view', 'can:damaged-reports.print'])->name('damaged.print');
    Route::get('out-of-stock', OutOfStockReportController::class)->middleware('can:stock-reports.view')->name('out-of-stock');
    Route::get('expenses', ExpenseReportController::class)->middleware(['can:shifts.view', 'can:expense-reports.view'])->name('expenses');
    Route::get('current-shift', [CashierShiftController::class, 'current'])->middleware(['can:shifts.view', 'can:shift-reports.view'])->name('current-shift');
});
