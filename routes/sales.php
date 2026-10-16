<?php

use App\Http\Controllers\Sales\PosController;
use App\Http\Controllers\Sales\SaleController;
use App\Http\Controllers\Sales\SalePaymentController;
use App\Http\Controllers\Sales\SaleReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('pos', [PosController::class, 'index'])->middleware('can:sales.pos')->name('pos.index');
    Route::post('pos/checkout', [PosController::class, 'checkout'])->middleware(['can:sales.pos', 'can:sales.create', 'can:sales.view'])->name('pos.checkout');

    Route::get('sales', [SaleController::class, 'index'])->middleware('can:sales.view')->name('sales.index');
    Route::get('sales/reports', [SaleController::class, 'reports'])->middleware('can:sales.reports.view')->name('sales.reports');
    Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt'])->middleware('can:sales.view')->name('sales.receipt');
    Route::get('sales/{sale}', [SaleController::class, 'show'])->middleware('can:sales.view')->name('sales.show');
    Route::post('sales/{sale}/payments', [SalePaymentController::class, 'store'])->middleware(['can:sales.view', 'can:sale-payments.create'])->name('sales.payments.store');
    Route::get('sales/{sale}/returns/create', [SaleReturnController::class, 'create'])->middleware(['can:sales.view', 'can:sale-returns.create'])->name('sales.returns.create');
    Route::post('sales/{sale}/returns', [SaleReturnController::class, 'store'])->middleware(['can:sales.view', 'can:sale-returns.create'])->name('sales.returns.store');
});
