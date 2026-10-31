<?php

use App\Http\Controllers\CashierShiftController;
use App\Http\Controllers\Sales\HeldCartController;
use App\Http\Controllers\Sales\PosController;
use App\Http\Controllers\Sales\SaleController;
use App\Http\Controllers\Sales\SalePaymentController;
use App\Http\Controllers\Sales\SaleReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('pos/held-carts', [HeldCartController::class, 'index'])->middleware('can:sales.pos')->name('pos.carts.index');
    Route::post('pos/held-carts', [HeldCartController::class, 'store'])->middleware('can:sales.pos')->name('pos.carts.store');
    Route::post('pos/held-carts/{cart}/claim', [HeldCartController::class, 'claim'])->middleware('can:sales.pos')->name('pos.carts.claim');
    Route::get('pos', [PosController::class, 'index'])->middleware('can:sales.pos')->name('pos.index');
    Route::post('pos/checkout', [PosController::class, 'checkout'])->middleware(['can:sales.pos', 'can:sales.create', 'can:sales.view'])->name('pos.checkout');

    Route::middleware(['can:sales.pos', 'can:shifts.view'])->prefix('pos/shifts')->name('pos.shifts.')->group(function () {
        Route::post('/', [CashierShiftController::class, 'store'])->name('store');
        Route::post('/{shift}/movements', [CashierShiftController::class, 'storeMovement'])->name('movements');
        Route::post('/{shift}/close', [CashierShiftController::class, 'close'])->name('close');
    });

    Route::get('sales/credit', [SaleController::class, 'index'])->defaults('credit', true)->middleware(['can:sales.view', 'can:credit-sales.view'])->name('sales.credit');
    Route::get('sales', [SaleController::class, 'index'])->middleware('can:sales.view')->name('sales.index');
    Route::get('sales/reports', [SaleController::class, 'reports'])->middleware('can:sales.reports.view')->name('sales.reports');
    Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt'])->middleware(['can:sales.view', 'can:sales-receipts.print'])->name('sales.receipt');
    Route::get('sales/{sale}', [SaleController::class, 'show'])->middleware('can:sales.view')->name('sales.show');
    Route::post('sales/{sale}/payments', [SalePaymentController::class, 'store'])->middleware(['can:sales.view', 'can:sale-payments.create'])->name('sales.payments.store');
    Route::get('sales/{sale}/returns/{saleReturn}/receipt', [SaleReturnController::class, 'receipt'])->middleware(['can:sales.view', 'can:return-receipts.print'])->name('sales.returns.receipt');
    Route::get('sales/{sale}/returns/create', [SaleReturnController::class, 'create'])->middleware(['can:sales.view', 'can:sale-returns.create'])->name('sales.returns.create');
    Route::post('sales/{sale}/returns', [SaleReturnController::class, 'store'])->middleware(['can:sales.view', 'can:sale-returns.create'])->name('sales.returns.store');
});
