<?php

use App\Http\Controllers\CashierShiftController;
use App\Http\Controllers\Reports\ActivityLogController;
use App\Http\Controllers\Reports\DamagedStockReportController;
use App\Http\Controllers\Reports\ExpenseReportController;
use App\Http\Controllers\Reports\OutOfStockReportController;
use App\Http\Controllers\Reports\SalesReportController;
use App\Http\Controllers\Reports\ShiftSalesReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('reports')->name('reports.')->group(function (): void {
    Route::get('daily-sales', [SalesReportController::class, 'daily'])->middleware('can:sales.reports.view')->name('daily-sales');
    Route::get('all-sales', [SalesReportController::class, 'all'])->middleware('can:sales.reports.view')->name('all-sales');
    Route::get('shift-sales', ShiftSalesReportController::class)->middleware(['can:shifts.view', 'can:shift-reports.view'])->name('shift-sales');
    Route::get('activity', ActivityLogController::class)->middleware('can:activity-logs.view')->name('activity');
    Route::get('product-activity', ActivityLogController::class)->middleware(['can:products.view', 'can:product-activity.view'])->name('product-activity');
    Route::get('damaged-stock', DamagedStockReportController::class)->middleware('can:damaged-reports.view')->name('damaged');
    Route::get('damaged-stock/print', DamagedStockReportController::class)->middleware(['can:damaged-reports.view', 'can:damaged-reports.print'])->name('damaged.print');
    Route::get('out-of-stock', OutOfStockReportController::class)->middleware('can:stock-reports.view')->name('out-of-stock');
    Route::get('expenses', ExpenseReportController::class)->middleware(['can:shifts.view', 'can:expense-reports.view'])->name('expenses');
    Route::get('current-shift', [CashierShiftController::class, 'current'])->middleware(['can:shifts.view', 'can:shift-reports.view'])->name('current-shift');
});
